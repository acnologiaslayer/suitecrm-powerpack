<?php
/**
 * InboundEmail Controller
 * Handles custom actions for the InboundEmail module
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/Controller/SugarController.php');

class InboundEmailController extends SugarController
{
    /**
     * Handle config action
     */
    public function action_config()
    {
        $this->view = 'config';
    }

    /**
     * Handle test action
     */
    public function action_test()
    {
        $this->view = 'test';
    }

    /**
     * Handle fetch action
     */
    public function action_fetch()
    {
        $this->view = 'fetch';
    }

    /**
     * Handle index action - redirect to config for admin convenience
     */
    public function action_index()
    {
        $this->view = 'index';
    }
}
