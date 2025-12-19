<?php
/**
 * VerbacallIntegration Controller
 *
 * Handles actions for signup link and payment link generation.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/Controller/SugarController.php');

class VerbacallIntegrationController extends SugarController
{
    public function __construct()
    {
        parent::__construct();

        // Map actions to views
        $this->action_remap['config'] = 'config';
        $this->action_remap['signuplink'] = 'signuplink';
        $this->action_remap['paymentlink'] = 'paymentlink';
    }

    /**
     * Admin configuration view
     */
    public function action_config()
    {
        $this->view = 'config';
    }

    /**
     * Generate and send signup link popup
     */
    public function action_signuplink()
    {
        $this->view = 'signuplink';
    }

    /**
     * Generate payment/discount link popup
     */
    public function action_paymentlink()
    {
        $this->view = 'paymentlink';
    }

    /**
     * Default index action - redirect to config
     */
    public function action_index()
    {
        $this->view = 'config';
    }
}
