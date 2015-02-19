<?php
/**
 * @package         CrowdFunding
 * @subpackage      Plugins
 * @author          Todor Iliev
 * @copyright       Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license         http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('crowdfunding.payment.plugin');

/**
 * CrowdFunding Blockchain payment plugin
 *
 * @package        CrowdFunding
 * @subpackage     Plugins
 */
class plgCrowdFundingPaymentBlockchain extends CrowdFundingPaymentPlugin
{
    protected $paymentService = "blockchain";

    protected $textPrefix     = "PLG_CROWDFUNDINGPAYMENT_BLOCKCHAIN";
    protected $debugType      = "BLOCKCHAIN_PAYMENT_PLUGIN_DEBUG";

    protected $extraDataKeys  = array("value", "input_address", "confirmations", "transaction_hash", "input_transaction_hash", "destination_address", "anonymous");

    /**
     * @var JApplicationSite
     */
    protected $app;

    /**
     * This method prepare and return address to Blockchain,
     * where the user have to go to make a donation.
     *
     * @param string $context
     * @param object $item
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item)
    {
        if (strcmp("com_crowdfunding.payment", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/blockchain";

        $html   = array();
        $html[] = '<div class="well">'; // Open "well".

        $html[] = '<h4><img src="' . $pluginURI . '/images/blockchain_icon.png" width="38" height="32" /> ' . JText::_($this->textPrefix . "_TITLE") . '</h4>';

        // Check for valid data.
        $receivingAddress   = JString::trim($this->params->get("receiving_address"));
        $callbackUrl        = $this->getCallbackUrl();

        if (!$receivingAddress or !$callbackUrl) {
            $html[] = '<div class="alert">' . JText::_($this->textPrefix . "_ERROR_PLUGIN_NOT_CONFIGURED") . '</div>';
            $html[] = '</div>'; // Close "well".

            return implode("\n", $html);
        }

        // Get intention
        $userId  = JFactory::getUser()->get("id");
        $aUserId = $this->app->getUserState("auser_id");

        $intention = $this->getIntention(array(
            "user_id"    => $userId,
            "auser_id"   => $aUserId,
            "project_id" => $item->id
        ));

        // Prepare transaction ID.
        jimport("itprism.string");
        $txnId         = new ITPrismString();
        $txnId->generateRandomString();
        $txnId = JString::strtoupper($txnId);

        // Store the unique key.
        $intention->setUniqueKey($txnId);
        $intention->store();

        // Prepare callback URL data.
        $callbackUrl .= "&intention_id=".(int)$intention->getId()."&txn_id=".$txnId;

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_CALLBACK_URL"), $this->debugType, $callbackUrl) : null;

        // Send request for button
        jimport("itprism.payment.blockchain.Blockchain");
        $blockchain = new Blockchain();
        $response   = $blockchain->Receive->generate($receivingAddress, $callbackUrl);

        // DEBUG DATA
        $responseResult = @var_export($response, true);
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RECEIVE_GENERATE_RESPONSE"), $this->debugType, $responseResult) : null;


        // Check for test mode.
        if ($this->params->get("test_mode", 1)) {
            $html[] = '<div class="alert">' . JText::_($this->textPrefix . "_ERROR_TEST_MODE") . '</div>';

            $html[] = '<label for="blockchain_callback_url">'.JText::_($this->textPrefix . "_CALLBACK_URL").'</label>';
            $html[] = '<textarea name="callback_url" id="blockchain_callback_url" class="input-block-level">'.$callbackUrl.'</textarea>';

        } else {

            $html[] = '<label for="blockchain_receiving_address">' . JText::_($this->textPrefix . "_RECEIVING_ADDRESS") . '</label>';
            $html[] = '<input class="input-block-level" type="text" value="' . $response->address . '" id="blockchain_receiving_address"/>';
            $html[] = '<p class="alert alert-info"><i class="icon-info-sign"></i> ' . JText::sprintf($this->textPrefix . "_SEND_COINS_TO_ADDRESS", $item->amountFormated) . '</p>';
            $html[] = '<a class="btn btn-primary" href="'.JRoute::_(CrowdFundingHelperRoute::getBackingRoute($item->slug, $item->catslug, "share")).'"><i class="icon-chevron-right"></i> ' . JText::_($this->textPrefix . "_CONTINUE_NEXT_STEP") . '</a>';
        }

        $html[] = '</div>'; // Close "well".

        return implode("\n", $html);
    }

    /**
     * This method processes transaction.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|array
     */
    public function onPaymentNotify($context, &$params)
    {
        if (strcmp("com_crowdfunding.notify.blockchain", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESPONSE"), $this->debugType, $_GET) : null;

        $result = array(
            "project"         => null,
            "reward"          => null,
            "transaction"     => null,
            "payment_session" => null,
            "payment_service" => "Blockchain",
            "response"        => "" // Response to the payment service.
        );

        // Get extension parameters
        jimport("crowdfunding.currency");
        $currencyId = $params->get("project_currency");
        $currency   = CrowdFundingCurrency::getInstance(JFactory::getDbo(), $currencyId, $params);

        // Get intention data
        $intentionId = $this->app->input->get->get("intention_id");

        jimport("crowdfunding.intention");
        $intention = new CrowdFundingIntention(JFactory::getDbo());
        $intention->load($intentionId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_INTENTION"), $this->debugType, $intention->getProperties()) : null;

        // Validate transaction data
        $validData = $this->validateData($_GET, $currency->getAbbr(), $intention);
        if (is_null($validData)) {
            return $result;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_VALID_DATA"), $this->debugType, $validData) : null;

        // Get project
        jimport("crowdfunding.project");
        $projectId = JArrayHelper::getValue($validData, "project_id");
        $project   = CrowdFundingProject::getInstance(JFactory::getDbo(), $projectId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;

        // Check for valid project
        if (!$project->getId()) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT"),
                $this->debugType,
                $validData
            );

            return $result;
        }

        // Set the receiver of funds
        $validData["receiver_id"] = $project->getUserId();

        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        $transactionData = $this->storeTransaction($validData, $project);
        if (is_null($transactionData)) {
            return $result;
        }

        // Update the number of distributed reward.
        $rewardId = JArrayHelper::getValue($transactionData, "reward_id");
        $reward   = null;
        if (!empty($rewardId)) {
            $reward = $this->updateReward($transactionData);

            // Validate the reward.
            if (!$reward) {
                $transactionData["reward_id"] = 0;
            }
        }

        //  Prepare the data that will be returned

        $result["transaction"] = JArrayHelper::toObject($transactionData);

        // Generate object of data based on the project properties
        $properties        = $project->getProperties();
        $result["project"] = JArrayHelper::toObject($properties);

        // Generate object of data based on the reward properties
        if (!empty($reward)) {
            $properties       = $reward->getProperties();
            $result["reward"] = JArrayHelper::toObject($properties);
        }

        // Generate data object, based on the intention properties.
        $properties       = $intention->getProperties();
        $result["payment_session"] = JArrayHelper::toObject($properties);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;

        // Remove intention
        $intention->delete();
        unset($intention);

        if (strcmp("completed", $result["transaction"]->txn_status) == 0) {
            $result["response"] = "*ok*";
        }

        return $result;
    }

    /**
     * This metod is executed after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param string $context
     * @param object $transaction Transaction data
     * @param Joomla\Registry\Registry $params Component parameters
     * @param object $project Project data
     * @param object $reward Reward data
     * @param object $paymentSession Payment session data.
     */
    public function onAfterPayment($context, &$transaction, &$params, &$project, &$reward, &$paymentSession)
    {
        if (strcmp("com_crowdfunding.notify.blockchain", $context) != 0) {
            return;
        }

        if ($this->app->isAdmin()) {
            return;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return;
        }

        // Send mails
        $this->sendMails($project, $transaction, $params);
    }

    /**
     * Validate transaction data.
     *
     * @param array                 $data
     * @param string                $currency
     * @param CrowdFundingIntention $intention
     *
     * @return null|array
     */
    protected function validateData($data, $currency, $intention)
    {
        // Get transaction ID.
        $txnId     = JArrayHelper::getValue($data, "txn_id");

        // Prepare transaction amount.
        $amount    = JArrayHelper::getValue($data, "value", 0.000, "float");
        $amount    = $amount / 100000000;

        // Transaction date.
        $date      = new JDate();

        // Get transaction status
        $status        = "pending";
        $confirmations = JArrayHelper::getValue($data, "confirmations", 0, "int");
        if ($confirmations >= 6) {
            $status = "completed";
        }

        // If the transaction has been made by anonymous user, reset reward. Anonymous users cannot select rewards.
        $rewardId = ($intention->isAnonymous()) ? 0 : (int)$intention->getRewardId();

        // Get additional information from transaction.
        $extraData = $this->prepareExtraData($data);

        // Prepare transaction data
        $transaction = array(
            "investor_id"      => (int)$intention->getUserId(),
            "project_id"       => (int)$intention->getProjectId(),
            "reward_id"        => (int)$rewardId,
            "service_provider" => "Blockchain",
            "txn_id"           => $txnId,
            "txn_amount"       => (float)$amount,
            "txn_currency"     => $currency,
            "txn_status"       => $status,
            "txn_date"         => $date->toSql(),
            "extra_data"       => $extraData
        );

        // Check User Id, Project ID and Transaction ID
        if (!$transaction["txn_amount"]) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_DATA"),
                $this->debugType,
                $transaction
            );

            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction
     *
     * @param array               $transactionData The data about transaction from the payment gateway.
     * @param CrowdFundingProject $project
     *
     * @return null|array
     */
    public function storeTransaction($transactionData, $project)
    {
        // Get transaction by txn ID
        jimport("crowdfunding.transaction");
        $keys        = array(
            "txn_id" => JArrayHelper::getValue($transactionData, "txn_id")
        );
        $transaction = new CrowdFundingTransaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_OBJECT"), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        if ($transaction->getId()) {

            // If the current status if completed,
            // stop the process.
            if ($transaction->isCompleted()) {
                return null;
            }

        }

        // Store the transaction data
        $transaction->bind($transactionData, array("extra_data"));
        $transaction->addExtraData($transactionData["extra_data"]);
        $transaction->store();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_OBJECT_AFTER_STORED_DATA"), $this->debugType, $transaction->getProperties()) : null;

        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue
        // and will process the project, rewards,...
        if (!$transaction->isCompleted()) {
            return null;
        }

        // Set transaction ID.
        $transactionData["id"] = $transaction->getId();

        // Update project funded amount
        $amount = JArrayHelper::getValue($transactionData, "txn_amount");
        $project->addFunds($amount);
        $project->updateFunds();

        return $transactionData;
    }
}
