<?php
/**
 * 2007-2019 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ImportNewsletterSubscribers extends Module
{
    const GUEST_NOT_REGISTERED = -1;
    const CUSTOMER_NOT_REGISTERED = 0;
    const GUEST_REGISTERED = 1;
    const CUSTOMER_REGISTERED = 2;

    private $html = '';
    private $tablename;

    protected $config_form = false;
    protected $support_url = 'https://addons.prestashop.com/fr/contactez-nous?id_product=46945';

    public function __construct()
    {
        $this->name = 'importnewslettersubscribers';
        $this->tab = 'emailing';
        $this->version = '1.0.1';
        $this->author = 'Mathieu Thollet';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->module_key = 'c602079bd23b4cf258e40cb379d09f32';

        parent::__construct();

        $this->displayName = $this->l('Import Newsletter Subscribers');
        $this->description = $this->l('Import Text / CSV file of newsletter subscribers.');
    }

    public function install()
    {
        return parent::install();
    }

    public function uninstall()
    {
        return parent::uninstall();
    }


    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $this->checkRequirements();
        if ((bool)Tools::isSubmit('submitImportNewsletterSubscribersModule')) {
            $this->postProcess();
        }
        $this->context->smarty->assign('module_dir', $this->_path);
        $this->context->smarty->assign('support_url', $this->support_url);
        $output = $this->html .
            $this->context->smarty->fetch($this->local_path . 'views/templates/admin/import.tpl') .
            $this->renderImportForm() .
            $this->context->smarty->fetch($this->local_path . 'views/templates/admin/support.tpl');
        return $output;
    }


    /**
     * Checks requirements
     */
    protected function checkRequirements()
    {
        if (version_compare(_PS_VERSION_, '1.6', '<')) {
            $this->html = '<p class="alert alert-danger">' . $this->l('Wrong Prestashop version : The module is only compatible with Prestashop 1.6 and Pretashop 1.7') . '</p>';
        }
        if (version_compare(_PS_VERSION_, '1.6', '>=') && version_compare(_PS_VERSION_, '1.7', '<') && !Module::isInstalled('blocknewsletter')) {
            $this->html = '<p class="alert alert-danger">' . $this->l('The module "Newsletter block" needs to be installed') . '</p>';
        }
        if (version_compare(_PS_VERSION_, '1.7', '>') && !Module::isInstalled('ps_emailsubscription')) {
            $this->html = '<p class="alert alert-danger">' . $this->l('The module "E-mail subscription form" needs to be installed') . '</p>';
        }
    }


    /**
     * Rendering of configuration form
     * @return mixed
     */
    protected function renderImportForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitImportNewsletterSubscribersModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );
        return $helper->generateForm(array($this->getImportForm()));
    }


    /**
     * Structure of the configuration form
     * @return array
     */
    protected function getImportForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Import Newsletter Subscribers - Import Text / CSV file'),
                    'icon' => 'icon-file',
                ),
                'input' => array(
                    array(
                        'type' => 'file',
                        'label' => $this->l('Text / CSV file'),
                        'name' => 'newsletter_subscribers_file',
                        'desc' =>
                            $this->l('Text file : one email address per row') . '<br/>' .
                            $this->l('or') . '<br/>' .
                            $this->l('CSV file (semicolon separated) : email_address;http_referer'),
                        'required' => true
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Import'),
                    'id' => 'submitImport',
                    'icon' => 'process-icon-upload'
                ),
            ),
        );
    }


    /**
     * PostProcess
     */
    protected function postProcess()
    {
        if (Tools::isSubmit('submitImportNewsletterSubscribersModule')) {
            $this->processImport();
        }
    }


    /**
     * Import file
     */
    protected function processImport()
    {
        set_time_limit(3600);
        if (!isset($_FILES['newsletter_subscribers_file']) || $_FILES['newsletter_subscribers_file']['name'] == '') {
            $this->html = '<p class="alert alert-danger">' . $this->l('No file has been uploaded') . '</p>';
        } elseif (!in_array(pathinfo($_FILES['newsletter_subscribers_file']['name'])['extension'], array('csv', 'txt', 'CSV', 'TXT'))) {
            $this->html = '<p class="alert alert-danger">' . $this->l('File type must be CSV or TXT') . '</p>';
        } else {
            $emailsAdded = array();
            $emailsAlreadyRegistered = array();
            $content = Tools::file_get_contents($_FILES['newsletter_subscribers_file']['tmp_name']);
            $rows = explode("\n", $content);
            foreach ($rows as $row) {
                $data = explode(';', $row);
                $email = trim($data[0]);
                if (trim($email) != '') {
                    if (isset($data[1])) {
                        $httpReferer = trim($data[1]);
                    } else {
                        $httpReferer = null;
                    }
                    // PS 1.7
                    if (Module::isInstalled('ps_emailsubscription')) {
                        $this->tablename = 'emailsubscription';
                        $register_status = $this->isNewsletterRegistered($email);
                        if ($register_status > 0) {
                            $emailsAlreadyRegistered[] = $email;
                        } else {
                            if ($this->register($email, $register_status)) {
                                if ($code = Configuration::get('NW_VOUCHER_CODE')) {
                                    $this->sendVoucher17($email, $code);
                                }
                                if (Configuration::get('NW_CONFIRMATION_EMAIL')) {
                                    $this->sendConfirmationEmail17($email);
                                }
                            }
                            $emailsAdded[] = $email;
                        }
                    } // PS 1.6
                    elseif (Module::isInstalled('blocknewsletter')) {
                        $this->tablename = 'newsletter';
                        $register_status = $this->isNewsletterRegistered($email);
                        if ($register_status > 0) {
                            $emailsAlreadyRegistered[] = $email;
                        } else {
                            if ($this->register($email, $register_status, $httpReferer)) {
                                if ($code = Configuration::get('NW_VOUCHER_CODE')) {
                                    $this->sendVoucher16($email, $code);
                                }
                                if (Configuration::get('NW_CONFIRMATION_EMAIL')) {
                                    $this->sendConfirmationEmail16($email);
                                }
                            }
                            $emailsAdded[] = $email;
                        }
                    }
                }
            }
            $message = $this->l('The file has been imported.');
            if (count($emailsAdded) > 0) {
                $message .= '<br/>' . $this->l('Emails imported') . ' : ' . implode(', ', $emailsAdded);
            }
            if (count($emailsAlreadyRegistered) > 0) {
                $message .= '<br/>' . $this->l('Emails already registered') . ' : ' . implode(', ', $emailsAlreadyRegistered);
            }
            $this->html = '<p class="alert alert-success">' . $message . '</p>';
        }
    }


    /**
     * Check if this mail is registered for newsletters.
     *
     * @param string $customer_email
     *
     * @return int -1 = not a customer and not registered
     *             0 = customer not registered
     *             1 = registered in block
     *             2 = registered in customer
     */
    public function isNewsletterRegistered($customer_email)
    {
        $sql = 'SELECT `email`
                FROM ' . _DB_PREFIX_ . $this->tablename . '
                WHERE `email` = \'' . pSQL($customer_email) . '\'
                AND id_shop = ' . $this->context->shop->id;

        if (Db::getInstance()->getRow($sql)) {
            return self::GUEST_REGISTERED;
        }

        $sql = 'SELECT `newsletter`
                FROM ' . _DB_PREFIX_ . 'customer
                WHERE `email` = \'' . pSQL($customer_email) . '\'
                AND id_shop = ' . $this->context->shop->id;

        if (!$registered = Db::getInstance()->getRow($sql)) {
            return self::GUEST_NOT_REGISTERED;
        }

        if ($registered['newsletter'] == '1') {
            return self::CUSTOMER_REGISTERED;
        }

        return self::CUSTOMER_NOT_REGISTERED;
    }


    /**
     * Subscribe a guest to the newsletter
     *
     * @param string $email
     * @param bool $active
     * @param string $httpReferer
     *
     * @return bool
     */
    protected function registerGuest($email, $active = true, $httpReferer = '')
    {
        $sql = 'INSERT INTO ' . _DB_PREFIX_ . $this->tablename . ' (id_shop, id_shop_group, email, newsletter_date_add, ip_registration_newsletter, http_referer, active)
				VALUES
				(' . $this->context->shop->id . ',
				' . $this->context->shop->id_shop_group . ',
				\'' . pSQL($email) . '\',
				NOW(),
				\'' . pSQL(Tools::getRemoteAddr()) . '\',
				\'' . $httpReferer . '\',				
				' . (int)$active . '
				)';

        return Db::getInstance()->execute($sql);
    }


    /**
     * Return a token associated to an user
     *
     * @param string $email
     * @param string $register_status
     */
    protected function getToken($email, $register_status)
    {
        if (in_array($register_status, array(self::GUEST_NOT_REGISTERED, self::GUEST_REGISTERED))) {
            $sql = 'SELECT MD5(CONCAT( `email` , `newsletter_date_add`, \'' . pSQL(Configuration::get('NW_SALT')) . '\')) as token
					FROM `' . _DB_PREFIX_ . $this->tablename . '`
					WHERE `active` = 0
					AND `email` = \'' . pSQL($email) . '\'';
        } elseif ($register_status == self::CUSTOMER_NOT_REGISTERED) {
            $sql = 'SELECT MD5(CONCAT( `email` , `date_add`, \'' . pSQL(Configuration::get('NW_SALT')) . '\' )) as token
					FROM `' . _DB_PREFIX_ . 'customer`
					WHERE `newsletter` = 0
					AND `email` = \'' . pSQL($email) . '\'';
        }

        return Db::getInstance()->getValue($sql);
    }


    /**
     * Subscribe an email to the newsletter. It will create an entry in the newsletter table
     * or update the customer table depending of the register status
     *
     * @param string $email
     * @param int $register_status
     * @param string $httpReferer
     */
    protected function register($email, $register_status, $httpReferer = '')
    {
        if ($register_status == self::GUEST_NOT_REGISTERED) {
            return $this->registerGuest($email, true, $httpReferer);
        }
        if ($register_status == self::CUSTOMER_NOT_REGISTERED) {
            return $this->registerUser($email, true);
        }
        return false;
    }


    /**
     * Subscribe a customer to the newsletter.
     *
     * @param string $email
     *
     * @return bool
     */
    protected function registerUser($email)
    {
        $sql = 'UPDATE ' . _DB_PREFIX_ . 'customer
                SET `newsletter` = 1, newsletter_date_add = NOW(), `ip_registration_newsletter` = \'' . pSQL(Tools::getRemoteAddr()) . '\'
                WHERE `email` = \'' . pSQL($email) . '\'
                AND id_shop = ' . $this->context->shop->id;

        return Db::getInstance()->execute($sql);
    }


    /**
     * Send an email containing a voucher code
     *
     * @param $email
     * @param $code
     *
     * @return bool|int
     */
    protected function sendVoucher16($email, $code)
    {
        return Mail::Send(
            $this->context->language->id,
            'newsletter_voucher',
            Mail::l('Newsletter voucher', $this->context->language->id),
            array('{discount}' => $code),
            $email,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'blocknewsletter/mails/',
            false,
            $this->context->shop->id
        );
    }


    /**
     * Send a confirmation email
     *
     * @param string $email
     *
     * @return bool
     */
    protected function sendConfirmationEmail16($email)
    {
        return Mail::Send(
            $this->context->language->id,
            'newsletter_conf',
            Mail::l('Newsletter confirmation', $this->context->language->id),
            array(),
            pSQL($email),
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'blocknewsletter/mails/',
            false,
            $this->context->shop->id
        );
    }


    /**
     * Send an email containing a voucher code.
     *
     * @param $email
     * @param $code
     *
     * @return bool|int
     */
    protected function sendVoucher17($email, $code)
    {
        $language = new Language($this->context->language->id);
        return Mail::Send(
            $this->context->language->id,
            'newsletter_voucher',
            $this->trans(
                'Newsletter voucher',
                array(),
                'Emails.Subject',
                $language->locale
            ),
            array(
                '{discount}' => $code,
            ),
            $email,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'ps_emailsubscription/mails/',
            false,
            $this->context->shop->id
        );
    }

    /**
     * Send a confirmation email.
     *
     * @param string $email
     *
     * @return bool
     */
    protected function sendConfirmationEmail17($email)
    {
        $language = new Language($this->context->language->id);
        return Mail::Send(
            $this->context->language->id,
            'newsletter_conf',
            $this->trans(
                'Newsletter confirmation',
                array(),
                'Emails.Subject',
                $language->locale
            ),
            array(),
            pSQL($email),
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'ps_emailsubscription/mails/',
            false,
            $this->context->shop->id
        );
    }
}
