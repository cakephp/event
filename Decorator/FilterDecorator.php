<?php
/**
 * CakePHP : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP Project
 * @since         3.3.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Event\Decorator;

use RuntimeException;

class FilterDecorator extends EventDecorator {

    /**
     * @inheritdoc
     */
    public function __invoke() {
        $args = func_get_args();
        if (!$this->canTrigger($args[0])) {
            return false;
        }
        return call_user_func_array('parent::__invoke', $args);
    }

    /**
     * Checks if the event is triggered for this listener.
     *
     * @param \Cake\Event\Event $event Event object.
     * @return bool
     */
    public function canTrigger(Event $event)
    {
        $if = $this->_evaluateCondition('if', $event);
        $unless = $this->_evaluateCondition('unless', $event);

        return $if && !$unless;
    }

    /**
     * Evaluates the filter conditions
     *
     * @param string $condition Condition type
     * @param \Cake\Event\Event $event Event objekt
     * @return bool
     */
    protected function _evaluateCondition($condition, Event $event)
    {
        if (!isset($this->_options[$condition])) {
            return true;
        }
        if (!is_callable($this->_options[$condition])) {
            throw new RuntimeException('Is not a callable!');
        }
        return $this->_options[$condition]($event);
    }
}
