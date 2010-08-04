<?php 
/**
 * @version $Id$
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @copyright Center for History and New Media, 2010
 * @package Contribution
 */
 
/**
 * Controller for contributions themselves.
 */
class Contribution_ContributionController extends Omeka_Controller_Action
{   
    protected $_captcha;
    
    /**
     * Index action; simply forwards to contributeAction.
     */
    public function indexAction()
    {
        $this->_forward('contribute');
    }
    
    /**
     * Action for main contribution form.
     */
    public function contributeAction()
    {
        $this->_captcha = $this->_setupCaptcha();
        
        if ($this->_processForm($_POST)) {
            $route = $this->getFrontController()->getRouter()->getCurrentRouteName();
            $this->redirect->gotoRoute(array('action' => 'thankyou'), $route);
        } else {
            if ($this->_captcha) {
                $this->view->captchaScript = $this->_captcha->render(new Zend_View);
            }
            if (isset($_POST['submit-type'])) {
                $this->_setupContributeSubmit();
                $this->view->typeForm = $this->view->render('contribution/type-form.php');
            }
        }
    }
    
    /**
     * Action for AJAX request from contribute form.
     */
    public function typeFormAction()
    {
        $this->_setupContributeSubmit();
    }
    
    /**
     * Displays terms of service for contribution.
     */
    public function termsAction()
    {
    }
    
    /**
     * Displays a "Thank You" message to users who have contributed an item 
     * through the public form.
     */
    public function thankyouAction()
    {
    }
    
    /**
     * Common tasks whenever displaying submit form for contribution.
     */
    public function _setupContributeSubmit()
    {
        // Override default element form display        
        $this->view->addHelperPath(CONTRIBUTION_HELPERS_DIR, 'Contribution_View_Helper');
        $item = new Item;
        $this->view->item = $item;
        
        $typeId = $_POST['contribution_type'];
        $type = get_db()->getTable('ContributionType')->find($typeId);
        $this->view->type = $type;
    }
    
    /**
     * Creates the reCAPTCHA object and returns it.
     * 
     * @return Zend_Captcha_Recaptcha|null
     */
    protected function _setupCaptcha()
    {
        $publicKey = get_option('contribution_recaptcha_public_key');
        $privateKey = get_option('contribution_recaptcha_private_key');

        if (empty($publicKey) or empty($privateKey)) {
           return;
        }
        
        // Originating request:
        $captcha = new Zend_Captcha_ReCaptcha(array(
            'pubKey' => $publicKey, 
            'privKey' => $privateKey));

        return $captcha;
    }
    
    /**
     * Handle the POST for adding an item via the public form.
     * 
     * Validate and save the contribution to the database.  Save the ID of the
     * new item to the session.  Redirect to the consent form. 
     * 
     * If validation fails, render the Contribution form again with errors.
     *
     * @param array $post POST array
     * @return bool
     */
    protected function _processForm($post)
    {       
        if (!empty($post)) {
            // The final form submit was not pressed.
            if (!isset($post['form-submit'])) {
                return false;
            }
            
            if (!$this->_validateContribution($post)) {
                return false;
            }
            
            $contributionTypeId = trim($post['contribution_type']);
            if ($contributionTypeId !== "" && is_numeric($contributionTypeId)) {
                $contributionType = get_db()->getTable('ContributionType')->find($contributionTypeId);
                $itemTypeId = $contributionType->getItemType()->id;
            } else {
                $this->flashError('You must select a type for your contribution.');
                return false;
            }
            
            $itemMetadata = array('public'       => false,
                                  'featured'     => false,
                                  'item_type_id' => $itemTypeId);
            
            $collectionId = get_option('contribution_collection_id');
            if (!empty($collectionId) && is_numeric($collectionId)) {
                $itemMetadata['collection_id'] = (int) $collectionId;
            }
            
            $builder = new ItemBuilder($itemMetadata);
            
            if (!$this->_processFileUpload($builder, $contributionType)) {
                return false;
            }
            
            try {
                $item = $builder->build();
            } catch(Omeka_Validator_Exception $e) {
                $this->flashValidatonErrors($e);
                return false;
            }
            
            $this->_addElementTextsToItem($item, $post['Elements']);
            // Allow plugins to deal with the inputs they may have added to the form.
            fire_plugin_hook('contribution_save_form', $contributionType, $item, $post);
            $item->save();

            $this->_processContributor($item, $post);
            
            return true;
        }
        return false;
    }
    
    /**
     * Deals with files specified on the contribution form.
     *
     * @param ItemBuilder $builder Builder for item to be contributed.
     * @param ContributionType $contributionType Type of contribution.
     * @return bool False only if errors occurred.
     */
    protected function _processFileUpload($builder, $contributionType) {
        if ($contributionType->isFileAllowed()) {
            $options = array();
            if ($contributionType->isFileRequired()) {
                $options['ignoreNoFile'] = false;
            } else {
                $options['ignoreNoFile'] = true;
            }
            
            // Add the whitelists for uploaded files
            $fileValidation = new ContributionFileValidation;
            $fileValidation->enableFilter();
            
            try {
                $builder->addFiles('Upload', 'contributed_file', $options);
            } catch (Omeka_File_Ingest_InvalidException $e) {
                // Copying this cruddy hack
                if (strstr($e->getMessage(), 
                           "The file 'contributed_file' was not uploaded")) {
                   $this->flashError("You must upload a file when contributing a {$contributionType->alias}.");
                } else {
                    $this->flashError($e->getMessage());
                }
                return false;
            } catch (Exception $e) {
                $this->flashError($e->getMessage());
                return false;
            }
        }
        return true;
    }

    /**
     * Deals with metadata about the item's contributor.
     *
     * @param Item $item Contributed item.
     * @param array $post POST array.
     */
    protected function _processContributor($item, $post)
    {
        $table = get_db()->getTable('ContributionContributor');
        $email = 'root@example.org';
        $name = 'John Flatness';
        $ip = $this->getRequest()->getClientIp();

        if (!($contributor = $table->findByEmail($email))) {
            $contributor = new ContributionContributor;
            $contributor->email = $email;
        }
        $contributor->setDottedIpAddress($ip);
        $contributor->name = $name;
        $contributor->save();

        $linkage = new ContributionContributedItem;
        $linkage->contributor_id = $contributor->id;
        $linkage->item_id = $item->id;
        $linkage->save();

        release_object($contributor);
        release_object($linkage);
    }
    
    /**
     * Adds ElementTexts to item.
     *
     * @param Item $item Item to add texts to.
     * @param array $elements Array of element inputs from form
     */
    protected function _addElementTextsToItem($item, $elements)
    {
        $elementTable = get_db()->getTable('Element');
        foreach($elements as $elementId => $elementTexts) {
            $element = $elementTable->find($elementId);
            foreach($elementTexts as $elementText) {
                $item->addTextForElement($element, $elementText['text']);
            }
        }
    }
    
    /**
     * Validate the contribution form submission.
     * 
     * Will flash validation errors that occur.
     * 
     * Verify the validity of the following form elements:
     *      Captcha (if set up)
     *      Terms agreement
     *      
     * @return bool
     */
    protected function _validateContribution($post)
    {
        $isValid = true;
        
        $errors = array();

        // ReCaptcha ignores the first argument.
        if ($this->_captcha and !$this->_captcha->isValid(null, $_POST)) {
            $errors[] = 'Your CAPTCHA submission was invalid, please try again.';
            $isValid = false;
        }
        
        if (!isset($post['terms-agree'])) {
            $errors[] = 'You must agree to the Terms and Conditions.';
            $isValid = false;
        }
        
        if ($errors) {
            $this->flashError(join("\n", $errors));
        }
        
        return $isValid;
    }
    
    /**
     * Send an email notification to the user who contributed the Item.
     * 
     * This email will appear to have been sent from the address specified via
     * the 'contribution_notification_email' option.
     * 
     * @param string $email Address to send to.
     * @param Item $item Item that was contributed via the form.
     * @return void
     * @todo Update for new Contribution
     */
    protected function _sendEmailNotification($toEmail, $item)
    {
        $fromEmail = get_option('contribution_notification_email');
        
        //If this field is empty, don't send the email
        if(empty($fromEmail)) {
            return;
        }
        
        $this->view->item = $item;
        $item->view->email = $toEmail;
                
        $mail = new Zend_Mail();
        $mail->setBodyText($this->view->render('contribution/email.php'));
        $mail->setFrom($fromEmail, get_option('site_title') . ' Administrator');
        $mail->addTo($toEmail);
        $mail->setSubject("Your " . get_option('site_title') . " Contribution");
        $mail->send();
    }
}
