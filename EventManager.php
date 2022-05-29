<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         2.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Event;

use Cake\Core\Exception\CakeException;
use InvalidArgumentException;

/**
 * The event manager is responsible for keeping track of event listeners, passing the correct
 * data to them, and firing them in the correct order, when associated events are triggered. You
 * can create multiple instances of this object to manage local events or keep a single instance
 * and pass it around to manage all events in your app.
 */
class EventManager implements EventManagerInterface
{
    /**
     * The default priority queue value for new, attached listeners
     *
     * @var int
     */
    public static int $defaultPriority = 10;

    /**
     * The globally available instance, used for dispatching events attached from any scope
     *
     * @var \Cake\Event\EventManager|null
     */
    protected static ?EventManager $_generalManager = null;

    /**
     * List of listener callbacks associated to
     *
     * @var array
     */
    protected array $_listeners = [];

    /**
     * Internal flag to distinguish a common manager from the singleton
     *
     * @var bool
     */
    protected bool $_isGlobal = false;

    /**
     * The event list object.
     *
     * @var \Cake\Event\EventList|null
     */
    protected ?EventList $_eventList = null;

    /**
     * Enables automatic adding of events to the event list object if it is present.
     *
     * @var bool
     */
    protected bool $_trackEvents = false;

    /**
     * Returns the globally available instance of a Cake\Event\EventManager
     * this is used for dispatching events attached from outside the scope
     * other managers were created. Usually for creating hook systems or inter-class
     * communication
     *
     * If called with the first parameter, it will be set as the globally available instance
     *
     * @param \Cake\Event\EventManager|null $manager Event manager instance.
     * @return \Cake\Event\EventManager The global event manager
     */
    public static function instance(?EventManager $manager = null): EventManager
    {
        if ($manager instanceof EventManager) {
            static::$_generalManager = $manager;
        }
        if (empty(static::$_generalManager)) {
            static::$_generalManager = new static();
        }

        static::$_generalManager->_isGlobal = true;

        return static::$_generalManager;
    }

    /**
     * @inheritDoc
     */
    public function on(
        EventListenerInterface|string $eventKey,
        callable|array $options = [],
        ?callable $callable = null
    ) {
        if ($eventKey instanceof EventListenerInterface) {
            $this->_attachSubscriber($eventKey);

            return $this;
        }

        if (!$callable && !is_callable($options)) {
            throw new InvalidArgumentException(
                'Second argument of EventManager::on() must be a callable if `$callable` is null.'
            );
        }

        if (!$callable) {
            $this->_listeners[$eventKey][static::$defaultPriority][] = [
                'callable' => $options(...),
            ];

            return $this;
        }

        $priority = $options['priority'] ?? static::$defaultPriority;
        $this->_listeners[$eventKey][$priority][] = [
            'callable' => $callable(...),
        ];

        return $this;
    }

    /**
     * Auxiliary function to attach all implemented callbacks of a Cake\Event\EventListenerInterface class instance
     * as individual methods on this manager
     *
     * @param \Cake\Event\EventListenerInterface $subscriber Event listener.
     * @return void
     */
    protected function _attachSubscriber(EventListenerInterface $subscriber): void
    {
        foreach ($subscriber->implementedEvents() as $eventKey => $handlers) {
            foreach ($this->normalizeHandlers($subscriber, $handlers) as $handler) {
                $this->on($eventKey, $handler['settings'], $handler['callable']);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function off(
        EventListenerInterface|callable|string $eventKey,
        EventListenerInterface|callable|null $callable = null
    ) {
        if ($eventKey instanceof EventListenerInterface) {
            $this->_detachSubscriber($eventKey);

            return $this;
        }

        if (!is_string($eventKey)) {
            foreach (array_keys($this->_listeners) as $name) {
                $this->off($name, $eventKey);
            }

            return $this;
        }

        if ($callable instanceof EventListenerInterface) {
            $this->_detachSubscriber($callable, $eventKey);

            return $this;
        }

        if ($callable === null) {
            unset($this->_listeners[$eventKey]);

            return $this;
        }

        if (empty($this->_listeners[$eventKey])) {
            return $this;
        }

        $callable = $callable(...);
        foreach ($this->_listeners[$eventKey] as $priority => $callables) {
            foreach ($callables as $k => $callback) {
                if ($callback['callable'] == $callable) {
                    unset($this->_listeners[$eventKey][$priority][$k]);
                    break;
                }
            }
        }

        return $this;
    }

    /**
     * Auxiliary function to help detach all listeners provided by an object implementing EventListenerInterface
     *
     * @param \Cake\Event\EventListenerInterface $subscriber the subscriber to be detached
     * @param string|null $eventKey optional event key name to unsubscribe the listener from
     * @return void
     */
    protected function _detachSubscriber(EventListenerInterface $subscriber, ?string $eventKey = null): void
    {
        $events = $subscriber->implementedEvents();
        if (!empty($eventKey) && empty($events[$eventKey])) {
            return;
        }
        if (!empty($eventKey)) {
            $events = [$eventKey => $events[$eventKey]];
        }
        foreach ($events as $key => $handlers) {
            foreach ($this->normalizeHandlers($subscriber, $handlers) as $handler) {
                $this->off($key, $handler['callable']);
            }
        }
    }

    /**
     * Builds an array of normalized handlers.
     *
     * A normalized handler is an aray with these keys:
     *
     *  - `callable` - The event handler callable
     *  - `settings` - The event handler settings
     *
     * @param \Cake\Event\EventListenerInterface $subscriber Event subscriber
     * @param array|string $handlers Event handlers
     * @return array
     */
    protected function normalizeHandlers(EventListenerInterface $subscriber, array|string $handlers): array
    {
        // Check if an array of handlers not single handler config array
        if (is_array($handlers) && !isset($handlers['callable'])) {
            foreach ($handlers as &$handler) {
                $handler = $this->normalizeHandler($subscriber, $handler);
            }

            return $handlers;
        }

        return [$this->normalizeHandler($subscriber, $handlers)];
    }

    /**
     * Builds a single normalized handler.
     *
     * A normalized handler is an aray with these keys:
     *
     *  - `callable` - The event handler callable
     *  - `settings` - The event handler settings
     *
     * @param \Cake\Event\EventListenerInterface $subscriber Event subscriber
     * @param array|string $handler Event handler
     * @return array
     */
    protected function normalizeHandler(EventListenerInterface $subscriber, array|string $handler): array
    {
        if (is_string($handler)) {
            return ['callable' => [$subscriber, $handler], 'settings' => []];
        }

        $method = $handler['callable'];
        unset($handler['callable']);

        return ['callable' => [$subscriber, $method], 'settings' => $handler];
    }

    /**
     * @inheritDoc
     */
    public function dispatch($event): EventInterface
    {
        if (is_string($event)) {
            $event = new Event($event);
        }

        $listeners = $this->listeners($event->getName());

        if ($this->_trackEvents) {
            $this->addEventToList($event);
        }

        if (!$this->_isGlobal && static::instance()->isTrackingEvents()) {
            static::instance()->addEventToList($event);
        }

        if (empty($listeners)) {
            return $event;
        }

        foreach ($listeners as $listener) {
            if ($event->isStopped()) {
                break;
            }
            $result = $this->_callListener($listener['callable'], $event);
            if ($result === false) {
                $event->stopPropagation();
            }
            if ($result !== null) {
                $event->setResult($result);
            }
        }

        return $event;
    }

    /**
     * Calls a listener.
     *
     * @param callable $listener The listener to trigger.
     * @param \Cake\Event\EventInterface $event Event instance.
     * @return mixed The result of the $listener function.
     */
    protected function _callListener(callable $listener, EventInterface $event): mixed
    {
        $data = (array)$event->getData();

        return $listener($event, ...array_values($data));
    }

    /**
     * @inheritDoc
     */
    public function listeners(string $eventKey): array
    {
        $localListeners = [];
        if (!$this->_isGlobal) {
            $localListeners = $this->prioritisedListeners($eventKey);
            $localListeners = empty($localListeners) ? [] : $localListeners;
        }
        $globalListeners = static::instance()->prioritisedListeners($eventKey);
        $globalListeners = empty($globalListeners) ? [] : $globalListeners;

        $priorities = array_merge(array_keys($globalListeners), array_keys($localListeners));
        $priorities = array_unique($priorities);
        asort($priorities);

        $result = [];
        foreach ($priorities as $priority) {
            if (isset($globalListeners[$priority])) {
                $result = array_merge($result, $globalListeners[$priority]);
            }
            if (isset($localListeners[$priority])) {
                $result = array_merge($result, $localListeners[$priority]);
            }
        }

        return $result;
    }

    /**
     * Returns the listeners for the specified event key indexed by priority
     *
     * @param string $eventKey Event key.
     * @return array
     */
    public function prioritisedListeners(string $eventKey): array
    {
        if (empty($this->_listeners[$eventKey])) {
            return [];
        }

        return $this->_listeners[$eventKey];
    }

    /**
     * Returns the listeners matching a specified pattern
     *
     * @param string $eventKeyPattern Pattern to match.
     * @return array
     */
    public function matchingListeners(string $eventKeyPattern): array
    {
        $matchPattern = '/' . preg_quote($eventKeyPattern, '/') . '/';
        $matches = array_intersect_key(
            $this->_listeners,
            array_flip(
                preg_grep($matchPattern, array_keys($this->_listeners), 0)
            )
        );

        return $matches;
    }

    /**
     * Returns the event list.
     *
     * @return \Cake\Event\EventList|null
     */
    public function getEventList(): ?EventList
    {
        return $this->_eventList;
    }

    /**
     * Adds an event to the list if the event list object is present.
     *
     * @param \Cake\Event\EventInterface $event An event to add to the list.
     * @return $this
     */
    public function addEventToList(EventInterface $event)
    {
        if ($this->_eventList) {
            $this->_eventList->add($event);
        }

        return $this;
    }

    /**
     * Enables / disables event tracking at runtime.
     *
     * @param bool $enabled True or false to enable / disable it.
     * @return $this
     */
    public function trackEvents(bool $enabled)
    {
        $this->_trackEvents = $enabled;

        return $this;
    }

    /**
     * Returns whether this manager is set up to track events
     *
     * @return bool
     */
    public function isTrackingEvents(): bool
    {
        return $this->_trackEvents && $this->_eventList;
    }

    /**
     * Enables the listing of dispatched events.
     *
     * @param \Cake\Event\EventList $eventList The event list object to use.
     * @return $this
     */
    public function setEventList(EventList $eventList)
    {
        $this->_eventList = $eventList;
        $this->_trackEvents = true;

        return $this;
    }

    /**
     * Disables the listing of dispatched events.
     *
     * @return $this
     */
    public function unsetEventList()
    {
        $this->_eventList = null;
        $this->_trackEvents = false;

        return $this;
    }

    /**
     * Debug friendly object properties.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $properties = get_object_vars($this);
        $properties['_generalManager'] = '(object) EventManager';
        $properties['_listeners'] = [];
        foreach ($this->_listeners as $key => $priorities) {
            $listenerCount = 0;
            foreach ($priorities as $listeners) {
                $listenerCount += count($listeners);
            }
            $properties['_listeners'][$key] = $listenerCount . ' listener(s)';
        }
        if ($this->_eventList) {
            $count = count($this->_eventList);
            for ($i = 0; $i < $count; $i++) {
                /** @var \Cake\Event\Event $event */
                $event = $this->_eventList[$i];
                try {
                    $subject = $event->getSubject();
                    $properties['_dispatchedEvents'][] = $event->getName() . ' with subject ' . get_class($subject);
                } catch (CakeException) {
                    $properties['_dispatchedEvents'][] = $event->getName() . ' with no subject';
                }
            }
        } else {
            $properties['_dispatchedEvents'] = null;
        }
        unset($properties['_eventList']);

        return $properties;
    }
}
