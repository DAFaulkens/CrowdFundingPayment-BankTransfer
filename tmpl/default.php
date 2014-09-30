<?php
/**
 * @package      CrowdFunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2014 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

defined('_JEXEC') or die;

// Load initialization script.
$doc->addScript("plugins/crowdfundingpayment/banktransfer/js/script.js?v=".rawurlencode($this->version));
?>

<h4>
    <img width="30" height="26" src="plugins/crowdfundingpayment/banktransfer/images/bank_icon.png" />
    <?php echo JText::_($this->textPrefix . "_TITLE"); ?>
</h4>

<?php
// Check for valid beneficiary information. If missing information, display error message.
$beneficiaryInfo = JString::trim(strip_tags($this->params->get("beneficiary")));
if (!$beneficiaryInfo) {?>
    <div class="alert"><?php echo JText::_($this->textPrefix . "_ERROR_PLUGIN_NOT_CONFIGURED"); ?></div>
    <?php
    return;
}?>

<div><?php echo nl2br($this->params->get("beneficiary")); ?></div>

<?php
if ($this->params->get("display_additional_info", 1)) {
    $additionalInfo = JString::trim($this->params->get("additional_info"));

    if (!empty($additionalInfo)) {?>
    <p class="sticky"><?php echo htmlspecialchars($additionalInfo, ENT_QUOTES, "UTF-8"); ?></p>;
    <?php } else { ?>
    <p class="sticky"><?php echo JText::_($this->textPrefix . "_INFO"); ?></p>
    <?php } ?>

<?php } ?>

<div class="alert alert-info hide" id="js-bt-alert"></div>

<div class="clearfix"></div>
<a href="#" class="btn btn-primary" id="js-register-bt"><?php echo JText::_($this->textPrefix . "_MAKE_BANK_TRANSFER"); ?></a>
<a href="#" class="btn btn-success hide" id="js-continue-bt"><?php echo JText::_($this->textPrefix . "_CONTINUE_NEXT_STEP"); ?></a>


<div class="modal hide fade" id="js-banktransfer-modal">
    <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h3><?php echo JText::_($this->textPrefix . "_TITLE"); ?></h3>
    </div>
    <div class="modal-body">
        <p><?php echo JText::_($this->textPrefix . "_REGISTER_TRANSACTION_QUESTION"); ?></p>
    </div>
    <div class="modal-footer">
        <img src="media/com_crowdfunding/images/ajax-loader.gif" width="16" height="16" id="js-banktransfer-ajax-loading" style="display: none;" />
        <button class="btn btn-primary" id="js-btbtn-yes" data-project-id="<?php echo $item->id; ?>" data-amount="<?php echo $item->amount; ?>"><?php echo JText::_("JYES"); ?></button>
        <button class="btn" id="js-btbtn-no"><?php echo JText::_("JNO"); ?></button>
    </div>
</div>