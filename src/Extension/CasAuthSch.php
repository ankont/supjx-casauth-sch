<?php
declare(strict_types=1);

namespace SuperSoft\Plugin\System\CasAuthSch\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Event\Application\AfterRouteEvent;
use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
use Joomla\CMS\Event\Model\PrepareDataEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\User\AfterDeleteEvent;
use Joomla\CMS\Event\User\AfterLoginEvent;
use Joomla\CMS\Event\User\AfterSaveEvent;
use Joomla\CMS\Event\User\LoginButtonsEvent;
use Joomla\CMS\Event\User\LoginEvent;
use Joomla\CMS\Event\User\LoginFailureEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;

final class CasAuthSch extends CMSPlugin implements SubscriberInterface
{
    private const PROFILE_GROUP = 'casauthsch';
    private const PROFILE_KEY_PREFIX = 'casauth_sch';
    private const PROFILE_FIELD_UID = 'uid';
    private const PROFILE_FIELD_MAIL = 'mail';
    private const PROFILE_FIELD_FULLNAME = 'fullname';
    private const PROFILE_FIELD_LINKED_AT = 'linked_at';
    private const PROFILE_FIELD_LAST_LOGIN_AT = 'last_login_at';
    private const PROFILE_FIELD_AUTOLINK_BLOCKED = 'autolink_blocked';
    private const PROFILE_FIELD_CLEAR_LINK = 'clear_link';
    private const USERNAME_SUFFIX = '-sch';
    private const MAX_USERNAME_ATTEMPTS = 50;
    private const LOG_CATEGORY = 'casauth_sch.attributes';
    private const FLOW_LOG_CATEGORY = 'casauth_sch.flow';
    private const LOG_FILE = 'plg_system_casauth_sch.php';
    private const SESSION_UI_ERROR = 'casauth_sch.ui_error';
    private const SESSION_RETURN = 'casauth_sch.return';
    private const SESSION_IS_SSO = 'casauth_sch.is_sso';
    private const SESSION_ADMIN_INTENT = 'casauth_sch.admin_intent';
    private const UI_ERROR_QUERY_PARAM = 'casauth_sch_ui_error';
    private const ADMIN_INTENT_QUERY_PARAM = 'casauth_sch_admin';

    private static bool $loggerConfigured = false;

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute' => 'onAfterRoute',
            'onBeforeCompileHead' => 'onBeforeCompileHead',
            'onUserLoginButtons' => 'onUserLoginButtons',
            'onUserAfterLogin' => 'onUserAfterLogin',
            'onContentPrepareData' => 'onContentPrepareData',
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onUserAfterSave' => 'onUserAfterSave',
            'onUserAfterDelete' => 'onUserAfterDelete',
        ];
    }

    public function onAfterRoute(AfterRouteEvent $event): void
    {
        $app = $event->getApplication();

        if (!$app instanceof CMSApplicationInterface) {
            return;
        }

        if ($app->isClient('administrator')) {
            if ($this->shouldStartAdministratorLogin($app)) {
                $this->handleAdministratorLoginStart($app);

                return;
            }

            $this->deliverAdministratorUiError($app);

            if ($this->shouldHandleAdministratorLogout($app)) {
                try {
                    $this->logFlowDebug('admin.logout.dispatch', [
                        'user_id' => (int) $app->getIdentity()->id,
                        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                    ]);
                    $this->handleAdministratorLogout($app);
                } catch (\Throwable $exception) {
                    $this->logFlowDebug('admin.logout.exception', [
                        'message' => $exception->getMessage(),
                    ]);
                    $app->enqueueMessage($exception->getMessage(), 'error');
                }
            }

            return;
        }

        if (!$app->isClient('site')) {
            return;
        }

        if ($this->shouldHandleLogout($app)) {
            try {
                $this->logFlowDebug('logout.dispatch', [
                    'user_id' => (int) $app->getIdentity()->id,
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                ]);
                $this->handleLogout($app);
            } catch (\Throwable $exception) {
                $this->logFlowDebug('logout.exception', [
                    'message' => $exception->getMessage(),
                ]);
                $app->enqueueMessage($exception->getMessage(), 'error');
            }
        }

        if ($app->getIdentity()->guest) {
            if ($this->shouldHandleLogin($app)) {
                $this->handleLogin($app);
            }

            return;
        }
    }

    public function onBeforeCompileHead(BeforeCompileHeadEvent $event): void
    {
        $app = $event->getApplication();
        $document = $event->getDocument();

        if (
            !$app instanceof CMSApplicationInterface
            || !$app->isClient('site')
            || !$document instanceof HtmlDocument
        ) {
            return;
        }

        $message = (string) $app->getSession()->get(self::SESSION_UI_ERROR, '');

        if ($message === '') {
            $rawMessage = $app->getInput()->getString(self::UI_ERROR_QUERY_PARAM, '');
            $decodedMessage = base64_decode(strtr($rawMessage, ' ', '+'), true);

            if ($decodedMessage !== false) {
                $message = trim($decodedMessage);
            }
        }

        if ($message === '') {
            return;
        }

        $app->getSession()->remove(self::SESSION_UI_ERROR);
        $app->enqueueMessage($message, 'error');

        $queryParamName = self::UI_ERROR_QUERY_PARAM;
        $script = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    var message = %s;
    var attempts = 0;
    var queryParamName = %s;

    function injectPanelMessage() {
        var panelBody = document.querySelector('.t4-off-canvas-body');

        if (!panelBody) {
            return false;
        }

        var existing = panelBody.querySelector('.casauth-sch-panel-alert');

        if (existing) {
            existing.remove();
        }

        var alert = document.createElement('div');
        alert.className = 'alert alert-danger casauth-sch-panel-alert';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;

        var loginForm = panelBody.querySelector('.joms-form, form[name="login"], .mod-login, .mod-login__form');

        if (loginForm && loginForm.parentNode) {
            loginForm.parentNode.insertBefore(alert, loginForm);
        } else {
            panelBody.insertBefore(alert, panelBody.firstChild);
        }

        removeMatchingSystemMessages();

        return true;
    }

    function removeMatchingSystemMessages() {
        var normalizedMessage = (message || '').replace(/\s+/g, ' ').trim();

        if (!normalizedMessage) {
            return;
        }

        document.querySelectorAll('#system-message-container .alert, #system-message-container joomla-alert, joomla-alert').forEach(function (node) {
            var text = (node.textContent || '').replace(/\s+/g, ' ').trim();

            if (!text || text !== normalizedMessage) {
                return;
            }

            node.remove();
        });

        var container = document.getElementById('system-message-container');

        if (container && !(container.textContent || '').trim()) {
            container.remove();
        }
    }

    function clearMessageFromUrl() {
        try {
            var url = new URL(window.location.href);

            if (!url.searchParams.has(queryParamName)) {
                return;
            }

            url.searchParams.delete(queryParamName);
            window.history.replaceState({}, document.title, url.toString());
        } catch (error) {
            // Ignore URL cleanup failures.
        }
    }

    function openPanel() {
        var trigger = document.getElementById('triggerButton');
        var panel = document.querySelector('.t4-offcanvas');

        injectPanelMessage();

        if (!trigger || !panel) {
            return;
        }

        if (panel.classList.contains('is-open')) {
            return;
        }

        if (window.jQuery && window.jQuery(trigger).data('offcanvas-trigger-component')) {
            trigger.click();
            setTimeout(injectPanelMessage, 200);
            return;
        }

        if (attempts < 20) {
            attempts++;
            window.setTimeout(openPanel, 150);
        }
    }

    clearMessageFromUrl();
    openPanel();
});
JS;

        $document->addStyleDeclaration(
            '.casauth-sch-panel-alert{margin:0 0 1rem; font-size:1rem; line-height:1.45;}'
        );
        $document->addScriptDeclaration(sprintf(
            $script,
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($queryParamName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
    }

    public function onUserLoginButtons(LoginButtonsEvent $event): void
    {
        if (!$this->shouldDisplaySsoButton()) {
            return;
        }

        $formId = preg_replace('/[^A-Za-z0-9_-]/', '-', $event->getFormId()) ?: 'login-form';
        $app = Factory::getApplication();
        $adminIntent = $app instanceof CMSApplicationInterface && $app->isClient('administrator');
        $loginUrl = $adminIntent ? $this->getAdministratorSsoKickoffUrl() : $this->getSsoLoginUrl();

        $this->logFlowDebug('button.render', [
            'form_id' => $formId,
            'admin_intent' => $adminIntent,
            'client' => $app instanceof CMSApplicationInterface ? ($app->isClient('administrator') ? 'administrator' : ($app->isClient('site') ? 'site' : 'other')) : 'unknown',
            'login_url' => $loginUrl,
        ]);

        $event->addResult([
            'label' => 'PLG_SYSTEM_CASAUTH_SCH_LOGIN_BUTTON_LABEL',
            'tooltip' => $adminIntent ? 'PLG_SYSTEM_CASAUTH_SCH_ADMIN_LOGIN_BUTTON_DESC' : 'PLG_SYSTEM_CASAUTH_SCH_LOGIN_BUTTON_DESC',
            'id' => 'plg_system_casauth_sch-' . $formId,
            'icon' => 'icon-lock icon-fw',
            'class' => 'plg-system-casauth-sch-login-button',
            'onclick' => "window.location.href='" . $loginUrl . "';",
        ]);
    }

    public function onUserAfterLogin(AfterLoginEvent $event): void
    {
        $options = $event->getOptions();
        $responseType = strtolower(trim((string) ($options['responseType'] ?? '')));

        if ($responseType !== strtolower('CASAuth SCH')) {
            return;
        }

        Factory::getApplication()->getSession()->set(self::SESSION_IS_SSO, true);
    }

    public function onContentPrepareData(PrepareDataEvent $event): void
    {
        if (!$this->isAdministratorProfileContext()) {
            return;
        }

        $context = $event->getContext();

        if (!\in_array($context, ['com_users.user', 'com_users.profile', 'com_admin.profile'], true)) {
            return;
        }

        $data = $event->getData();

        if (!\is_object($data)) {
            return;
        }

        $userId = $this->getUserIdFromFormData($data);

        if ($userId <= 0) {
            return;
        }

        $this->loadLanguage();
        $profile = $this->loadCasProfileData($userId);

        $data->{self::PROFILE_GROUP} = [
            self::PROFILE_FIELD_UID => $profile[self::PROFILE_FIELD_UID] ?? '',
            self::PROFILE_FIELD_MAIL => $profile[self::PROFILE_FIELD_MAIL] ?? '',
            self::PROFILE_FIELD_FULLNAME => $profile[self::PROFILE_FIELD_FULLNAME] ?? '',
            self::PROFILE_FIELD_LINKED_AT => $profile[self::PROFILE_FIELD_LINKED_AT] ?? '',
            self::PROFILE_FIELD_LAST_LOGIN_AT => $profile[self::PROFILE_FIELD_LAST_LOGIN_AT] ?? '',
            self::PROFILE_FIELD_AUTOLINK_BLOCKED => $profile[self::PROFILE_FIELD_AUTOLINK_BLOCKED] ?? '0',
            self::PROFILE_FIELD_CLEAR_LINK => '0',
        ];
    }

    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        if (!$this->isAdministratorProfileContext()) {
            return;
        }

        $form = $event->getForm();
        $data = $event->getData();

        if (!\in_array($form->getName(), ['com_users.user', 'com_users.profile', 'com_admin.profile'], true)) {
            return;
        }

        if ($data === null || $data === [] || $data === '') {
            $jformData = $this->getApplication()->getInput()->get('jform', [], 'array');

            if ($jformData !== []) {
                $data = $jformData;
            }
        }

        $userId = $this->getUserIdFromFormData($data);

        if ($userId <= 0) {
            return;
        }

        $this->loadLanguage();
        Form::addFormPath(JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name . '/forms');
        $form->loadFile(self::PROFILE_GROUP, false);

        $profile = $this->loadCasProfileData($userId);

        if (($profile[self::PROFILE_FIELD_UID] ?? '') === '') {
            $form->removeField(self::PROFILE_FIELD_CLEAR_LINK, self::PROFILE_GROUP);
        }
    }

    public function onUserAfterSave(AfterSaveEvent $event): void
    {
        $data = $event->getUser();
        $result = $event->getSavingResult();

        if (!$result || !\is_array($data)) {
            return;
        }

        $userId = ArrayHelper::getValue($data, 'id', 0, 'int');

        if ($userId <= 0) {
            $jformData = $this->getApplication()->getInput()->get('jform', [], 'array');
            $userId = ArrayHelper::getValue($jformData, 'id', 0, 'int');
        }

        if ($userId <= 0) {
            return;
        }

        $formData = $this->extractProfileFormDataFromSavePayload($data);

        if ($formData === null) {
            return;
        }

        $profile = $this->loadCasProfileData($userId);
        $clearLink = !empty($formData[self::PROFILE_FIELD_CLEAR_LINK]);
        $autolinkBlocked = !empty($formData[self::PROFILE_FIELD_AUTOLINK_BLOCKED]) ? '1' : '0';

        if ($clearLink) {
            $profile[self::PROFILE_FIELD_UID] = '';
            $profile[self::PROFILE_FIELD_MAIL] = '';
            $profile[self::PROFILE_FIELD_FULLNAME] = '';
            $profile[self::PROFILE_FIELD_LINKED_AT] = '';
            $profile[self::PROFILE_FIELD_LAST_LOGIN_AT] = '';
            $autolinkBlocked = '1';
        }

        $profile[self::PROFILE_FIELD_AUTOLINK_BLOCKED] = $autolinkBlocked;
        $this->saveCasProfileData($userId, $profile);
    }

    public function onUserAfterDelete(AfterDeleteEvent $event): void
    {
        if (!$event->getDeletingResult()) {
            return;
        }

        $user = $event->getUser();

        if (!\is_array($user)) {
            return;
        }

        $userId = ArrayHelper::getValue($user, 'id', 0, 'int');

        if ($userId <= 0) {
            return;
        }

        $this->deleteCasProfileData($userId);
    }

    private function shouldHandleLogin(CMSApplicationInterface $app): bool
    {
        $input = $app->getInput();
        $option = $input->getCmd('option');
        $view = $input->getCmd('view');

        if ($option !== 'com_users') {
            return false;
        }

        if ($view === 'login' && $input->getInt('casauth_sch', 0) === 1) {
            return true;
        }

        return $view === 'login' && (bool) $this->params->get('auto_redirect_login', 0);
    }

    private function shouldHandleLogout(CMSApplicationInterface $app): bool
    {
        $input = $app->getInput()->getInputForRequestMethod();
        $option = $input->getCmd('option');
        $task = $input->getCmd('task');
        $looksLikeLogoutRequest = $option === 'com_users' && \in_array($task, ['user.logout', 'user.menulogout'], true);

        if (!$looksLikeLogoutRequest) {
            return false;
        }

        $userId = (int) $app->getIdentity()->id;
        $sessionIsSso = (bool) $app->getSession()->get(self::SESSION_IS_SSO, false);
        $hasStoredLink = $userId > 0 && $this->hasStoredCasLink($userId);
        $tokenValid = Session::checkToken('request');

        $this->logFlowDebug('logout.request', [
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'option' => $option,
            'task' => $task,
            'user_id' => $userId,
            'identity_guest' => $app->getIdentity()->guest,
            'session_is_sso' => $sessionIsSso,
            'has_stored_cas_link' => $hasStoredLink,
            'token_valid' => $tokenValid,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'return' => (string) $input->get('return', ''),
        ]);

        if (!$tokenValid) {
            return false;
        }

        if ($sessionIsSso) {
            return true;
        }

        return $hasStoredLink;
    }

    private function isCurrentRequestSiteLogout(CMSApplicationInterface $app): bool
    {
        $input = $app->getInput()->getInputForRequestMethod();
        $option = $input->getCmd('option');
        $task = $input->getCmd('task');

        if ($option !== 'com_users') {
            return false;
        }

        return \in_array($task, ['user.logout', 'user.menulogout'], true);
    }

    private function handleLogin(CMSApplicationInterface $app): void
    {
        $session = $app->getSession();
        $returnTarget = '';
        $casAuthenticated = false;
        $adminIntent = $this->captureAdministratorIntent($app);
        $input = $app->getInput();

        $this->logFlowDebug('login.start', [
            'admin_intent' => $adminIntent,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'has_ticket' => trim($input->getString('ticket', '')) !== '',
            'raw_return' => (string) $input->getInputForRequestMethod()->get('return', ''),
            'admin_param' => $input->getInt(self::ADMIN_INTENT_QUERY_PARAM, 0),
            'stored_return' => (string) $session->get(self::SESSION_RETURN, ''),
        ]);

        try {
            if ($adminIntent && !$this->canUseAdministratorSso($app)) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_ADMIN_SHARED_SESSION'));
            }

            $returnTarget = $this->resolveLoginReturn($app, $adminIntent);

            if ($returnTarget !== '') {
                $session->set(self::SESSION_RETURN, $returnTarget);
            }

            $this->logFlowDebug('login.return.resolved', [
                'admin_intent' => $adminIntent,
                'return_target' => $returnTarget,
            ]);

            $this->bootPhpCas();
            \phpCAS::forceAuthentication();
            $casAuthenticated = true;

            $attributes = \phpCAS::hasAttributes() ? \phpCAS::getAttributes() : [];
            $casIdentity = $this->resolveCasIdentity($attributes, (string) \phpCAS::getUser());
            $this->logCasAttributes($attributes, $casIdentity);
            $this->assertAttributeAllowlists($attributes);
            $localUserResolution = $this->ensureLocalUser($casIdentity, $adminIntent);
            $localUser = $localUserResolution['user'];
            $localUserData = $localUserResolution['login'];
            $this->logFlowDebug('login.local_user.resolved', [
                'admin_intent' => $adminIntent,
                'user_id' => (int) $localUser->id,
                'username' => (string) $localUser->username,
                'can_login_admin' => $localUser->authorise('core.login.admin'),
            ]);
            $this->completeJoomlaLogin($app, $localUserData, $adminIntent);

            Factory::getApplication()->getSession()->set(self::SESSION_IS_SSO, true);

            $session->remove(self::SESSION_RETURN);
            $session->remove(self::SESSION_ADMIN_INTENT);
            $redirectTarget = $returnTarget;

            if ($adminIntent) {
                $this->assertLocalUserCanAccessAdministrator($localUser, true);
                $redirectTarget = $this->normalizeAdministratorReturnTarget($redirectTarget);
            }

            $this->logFlowDebug('login.redirect.final', [
                'admin_intent' => $adminIntent,
                'redirect_target' => $redirectTarget,
                'absolute_redirect' => $this->toAbsoluteUrl($redirectTarget),
            ]);

            $app->redirect($this->toAbsoluteUrl($redirectTarget));
            $app->close();
        } catch (\Throwable $exception) {
            $redirectTarget = (string) $session->get(self::SESSION_RETURN, '');
            $session->remove(self::SESSION_RETURN);
            $session->remove(self::SESSION_ADMIN_INTENT);
            $session->set(self::SESSION_UI_ERROR, $exception->getMessage());
            $fallbackTarget = $redirectTarget !== '' ? $redirectTarget : $returnTarget;

            if ($adminIntent) {
                $fallbackTarget = $this->normalizeAdministratorReturnTarget($fallbackTarget);
            }

            $finalRedirect = $this->toAbsoluteUrl($fallbackTarget);
            $finalRedirect = $this->appendUiErrorToUrl($finalRedirect, $exception->getMessage());

            if ($casAuthenticated) {
                $this->logFlowDebug('login.failure.cas_logout', [
                    'message' => $exception->getMessage(),
                    'redirect_url' => $finalRedirect,
                ]);

                $session->remove(self::SESSION_IS_SSO);

                try {
                    $this->bootPhpCas();
                    \phpCAS::logoutWithRedirectService($finalRedirect);
                } catch (\Throwable $logoutException) {
                    $this->logFlowDebug('login.failure.cas_logout.exception', [
                        'message' => $logoutException->getMessage(),
                    ]);
                }
            }

            if (!$adminIntent) {
                $app->enqueueMessage($exception->getMessage(), 'error');
            }
            $app->redirect($finalRedirect);
            $app->close();
        }
    }

    private function handleLogout(CMSApplicationInterface $app): void
    {
        $returnTarget = $this->resolveLogoutReturn($app);
        $options = [
            'clientid' => $app->get('shared_session', '0') ? null : 0,
        ];

        $this->logFlowDebug('logout.handle.start', [
            'user_id' => (int) $app->getIdentity()->id,
            'return_target' => $returnTarget,
            'logout_url' => $this->getLogoutUrl(),
        ]);

        $this->bootPhpCas();

        if ($app->logout(null, $options) !== true) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_LOCAL_LOGOUT'));
        }

        $app->getSession()->remove(self::SESSION_IS_SSO);
        $redirectUrl = $this->getLogoutUrl() . '?service=' . rawurlencode($this->toAbsoluteUrl($returnTarget));

        $this->logFlowDebug('logout.handle.redirect', [
            'redirect_url' => $redirectUrl,
        ]);

        \phpCAS::logoutWithRedirectService($this->toAbsoluteUrl($returnTarget));
    }

    private function handleAdministratorLogout(CMSApplicationInterface $app): void
    {
        $input = $app->getInput()->getInputForRequestMethod();
        $userId = $input->getInt('uid', 0);
        $clientId = $app->get('shared_session', '0') ? null : ($userId > 0 ? 0 : 1);
        $returnTarget = $this->normalizeAdministratorReturnTarget($this->getAdministratorEntryPath());

        $this->logFlowDebug('admin.logout.handle.start', [
            'user_id' => (int) $app->getIdentity()->id,
            'uid' => $userId,
            'return_target' => $returnTarget,
            'logout_url' => $this->getLogoutUrl(),
        ]);

        $this->bootPhpCas();

        if ($app->logout($userId > 0 ? $userId : null, ['clientid' => $clientId]) !== true) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_LOCAL_LOGOUT'));
        }

        $session = $app->getSession();
        $session->remove(self::SESSION_IS_SSO);
        $session->remove(self::SESSION_ADMIN_INTENT);

        $redirectUrl = $this->getLogoutUrl() . '?service=' . rawurlencode($this->toAbsoluteUrl($returnTarget));

        $this->logFlowDebug('admin.logout.handle.redirect', [
            'redirect_url' => $redirectUrl,
        ]);

        \phpCAS::logoutWithRedirectService($this->toAbsoluteUrl($returnTarget));
    }

    private function bootPhpCas(): void
    {
        $autoload = __DIR__ . '/../../vendor/autoload.php';

        if (!is_file($autoload)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_MISSING_VENDOR'));
        }

        require_once $autoload;

        $host = trim((string) $this->params->get('host', 'sso.sch.gr'));
        $port = (int) $this->params->get('port', 443);
        $context = trim((string) $this->params->get('context', '/'));
        $serviceUrl = $this->getServiceUrl();
        $caCert = trim((string) $this->params->get('ca_cert', ''));

        if ($host === '') {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_INVALID_HOST'));
        }

        if ($context === '') {
            $context = '/';
        }

        if ($context[0] !== '/') {
            $context = '/' . $context;
        }

        if ($caCert === '' || !is_file($caCert)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_INVALID_CA_CERT'));
        }

        if (!\phpCAS::isInitialized()) {
            \phpCAS::client(CAS_VERSION_3_0, $host, $port, $context, $this->getServiceBaseUrl($serviceUrl));
            \phpCAS::setFixedServiceURL($serviceUrl);
            \phpCAS::setCasServerCACert($caCert);
        }
    }

    private function shouldDisplaySsoButton(): bool
    {
        $app = Factory::getApplication();

        if (!$app instanceof CMSApplicationInterface) {
            return false;
        }

        if (!$app->getIdentity()->guest) {
            return false;
        }

        try {
            $document = $app->getDocument();
        } catch (\Throwable) {
            return false;
        }

        if (!$document instanceof HtmlDocument) {
            return false;
        }

        if ($app->isClient('site')) {
            return true;
        }

        return $app->isClient('administrator')
            && (bool) $this->params->get('enable_admin_sso', 0)
            && $this->canUseAdministratorSso($app);
    }

    private function getSsoLoginUrl(): string
    {
        $targetUrl = Uri::getInstance($this->getServiceUrl());
        $return = Uri::getInstance()->toString(['path', 'query', 'fragment']);

        if ($return !== '') {
            $targetUrl->setVar('return', base64_encode($return));
        }

        return $targetUrl->toString();
    }

    private function getAdministratorSsoKickoffUrl(): string
    {
        $currentUrl = Uri::getInstance();
        $currentUrl->setVar(self::ADMIN_INTENT_QUERY_PARAM, '1');
        $currentUrl->delVar(self::UI_ERROR_QUERY_PARAM);

        return $currentUrl->toString();
    }

    /**
     * @param   array<string, mixed>  $attributes
     *
     * @return  array{uid: string, email: string, fullname: string, type: string, language: string, umdobject: string}
     */
    private function resolveCasIdentity(array $attributes, string $casUser): array
    {
        $usernameAttribute = trim((string) $this->params->get('username_attribute', ''));
        $casUid = $this->getFirstAttributeValue($attributes, $usernameAttribute);

        if ($casUid === '') {
            $casUid = trim($casUser);
        }

        if ($casUid === '') {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_INVALID_USERNAME'));
        }

        $mailAttribute = trim((string) $this->params->get('mail_attribute', 'mail'));
        $fullNameAttribute = trim((string) $this->params->get('fullname_attribute', 'cn'));

        if ($mailAttribute === '') {
            $mailAttribute = 'mail';
        }

        if ($fullNameAttribute === '') {
            $fullNameAttribute = 'cn';
        }

        $email = trim($this->getFirstAttributeValue($attributes, $mailAttribute));
        $fullName = trim($this->getFirstAttributeValue($attributes, $fullNameAttribute));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        } else {
            $email = strtolower($email);
        }

        if ($fullName === '') {
            $fullName = $casUid;
        }

        return [
            'uid' => $casUid,
            'email' => $email,
            'fullname' => $fullName,
            'type' => 'CASAuth SCH',
            'language' => '',
            'umdobject' => trim($this->getFirstAttributeValue($attributes, 'umdobject')),
        ];
    }

    /**
     * @param   array{uid: string, email: string, fullname: string, type: string, language: string, umdobject: string}  $casIdentity
     *
     * @return  array{user: User, login: array<string, string>}
     */
    private function ensureLocalUser(array $casIdentity, bool $adminIntent = false): array
    {
        $userId = $this->findLinkedUserIdByCasUid($casIdentity['uid']);

        if ($userId > 0) {
            $user = $this->loadUserById($userId);

            if ($user->id) {
                $this->assertLocalUserIsActive($user);
                $this->assertLocalUserCanAccessAdministrator($user, $adminIntent);
                $this->syncLocalUser($user, $casIdentity);
                $this->storeCasLinkForUser((int) $user->id, $casIdentity);

                return [
                    'user' => $user,
                    'login' => $this->buildLoginUserData($user, $casIdentity),
                ];
            }

            $this->deleteCasProfileData($userId);
        }

        if ($casIdentity['email'] === '') {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_MISSING_EMAIL'));
        }

        $userId = $this->findExistingUserIdByEmail($casIdentity['email']);

        if ($userId > 0) {
            if ($this->isUserAutoLinkBlocked($userId)) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_AUTO_LINK_BLOCKED'));
            }

            $user = $this->loadUserById($userId);

            if (!$user->id) {
                throw new \RuntimeException($adminIntent ? Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_ADMIN_USER_NOT_FOUND') : Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_USER_NOT_FOUND'));
            }

            $this->assertLocalUserIsActive($user);
            $this->assertLocalUserCanAccessAdministrator($user, $adminIntent);
            $this->syncLocalUser($user, $casIdentity);
            $this->storeCasLinkForUser((int) $user->id, $casIdentity);

            return [
                'user' => $user,
                'login' => $this->buildLoginUserData($user, $casIdentity),
            ];
        }

        if ($adminIntent) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_ADMIN_USER_NOT_FOUND'));
        }

        if (!(bool) $this->params->get('auto_create', 1)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_USER_NOT_FOUND'));
        }

        $user = $this->createLocalUser($casIdentity);
        $this->storeCasLinkForUser((int) $user->id, $casIdentity);

        return [
            'user' => $user,
            'login' => $this->buildLoginUserData($user, $casIdentity),
        ];
    }

    /**
     * @param   array{uid: string, email: string, fullname: string, type: string, language: string, umdobject: string}  $casIdentity
     */
    private function createLocalUser(array $casIdentity): User
    {
        $user = new User();
        $user->set('name', $casIdentity['fullname']);
        $user->set('username', $this->findAvailableUsername($casIdentity['uid']));
        $user->set('email', $casIdentity['email']);
        $user->set('password', bin2hex(random_bytes(12)));
        $user->set('groups', [$this->resolveDefaultGroupIdForCasIdentity($casIdentity)]);

        if (!$user->save()) {
            $error = trim((string) $user->getError());
            $message = Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_CREATE_USER');

            if ($error !== '') {
                $message .= ' ' . $error;
            }

            throw new \RuntimeException($message);
        }

        return $user;
    }

    /**
     * @param   array{uid: string, email: string, fullname: string, type: string, language: string, umdobject: string}  $casIdentity
     */
    private function syncLocalUser(User $user, array $casIdentity): void
    {
        if (!(bool) $this->params->get('sync_profile', 1)) {
            return;
        }

        // Do not attempt frontend profile sync on privileged accounts; Joomla blocks it
        // and these users should usually be managed explicitly by an administrator.
        if ($user->authorise('core.admin')) {
            return;
        }

        $profile = $this->loadCasProfileData((int) $user->id);
        $previousCasFullname = trim((string) ($profile[self::PROFILE_FIELD_FULLNAME] ?? ''));
        $allowNameSync = $previousCasFullname === '' || $user->name === $previousCasFullname;
        $dirty = false;

        if ($allowNameSync && $user->name !== $casIdentity['fullname']) {
            $user->set('name', $casIdentity['fullname']);
            $dirty = true;
        }

        if ($casIdentity['email'] !== '' && $user->email !== $casIdentity['email']) {
            $user->set('email', $casIdentity['email']);
            $dirty = true;
        }

        if ($dirty && !$user->save()) {
            $error = trim((string) $user->getError());
            $message = Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_SYNC_USER');

            if ($error !== '') {
                $message .= ' ' . $error;
            }

            throw new \RuntimeException($message);
        }
    }

    /**
     * @param   array{uid: string, email: string, fullname: string, type: string, language: string, umdobject: string}  $casIdentity
     *
     * @return  array<string, string>
     */
    private function buildLoginUserData(User $user, array $casIdentity): array
    {
        return [
            'username' => $user->username,
            'fullname' => $user->name,
            'email' => $user->email,
            'type' => $casIdentity['type'],
            'language' => $casIdentity['language'],
        ];
    }

    private function findLinkedUserIdByCasUid(string $casUid): int
    {
        $casUid = trim($casUid);

        if ($casUid === '') {
            return 0;
        }

        $db = Factory::getDbo();
        $profileKey = self::PROFILE_KEY_PREFIX . '.' . self::PROFILE_FIELD_UID;
        $query = $db->getQuery(true)
            ->select($db->quoteName('user_id'))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('profile_key') . ' = :profileKey')
            ->where($db->quoteName('profile_value') . ' = :profileValue')
            ->bind(':profileKey', $profileKey, ParameterType::STRING)
            ->bind(':profileValue', $casUid, ParameterType::STRING);

        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    private function findExistingUserIdByEmail(string $email): int
    {
        $email = trim($email);

        if ($email === '') {
            return 0;
        }

        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__users'))
            ->where('LOWER(' . $db->quoteName('email') . ') = LOWER(:mail)')
            ->bind(':mail', $email, ParameterType::STRING);

        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    private function isUserAutoLinkBlocked(int $userId): bool
    {
        $profile = $this->loadCasProfileData($userId);

        return ($profile[self::PROFILE_FIELD_AUTOLINK_BLOCKED] ?? '0') === '1';
    }

    private function hasStoredCasLink(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $profile = $this->loadCasProfileData($userId);

        return ($profile[self::PROFILE_FIELD_UID] ?? '') !== '';
    }

    /**
     * @return  array<string, string>
     */
    private function loadCasProfileData(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('profile_key'),
                $db->quoteName('profile_value'),
            ])
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userId')
            ->where($db->quoteName('profile_key') . ' LIKE :profileKey')
            ->order($db->quoteName('ordering'));

        $profileKey = self::PROFILE_KEY_PREFIX . '.%';
        $query->bind(':userId', $userId, ParameterType::INTEGER);
        $query->bind(':profileKey', $profileKey, ParameterType::STRING);

        $db->setQuery($query);

        try {
            $rows = $db->loadRowList();
        } catch (\Throwable) {
            return [];
        }

        $profile = [];

        foreach ($rows as $row) {
            $key = str_replace(self::PROFILE_KEY_PREFIX . '.', '', (string) ($row[0] ?? ''));
            $value = trim((string) ($row[1] ?? ''));

            if ($key !== '') {
                $profile[$key] = $value;
            }
        }

        return $profile;
    }

    /**
     * @param   array<string, string>  $profile
     */
    private function saveCasProfileData(int $userId, array $profile): void
    {
        if ($userId <= 0) {
            return;
        }

        $normalized = [
            self::PROFILE_FIELD_UID => trim((string) ($profile[self::PROFILE_FIELD_UID] ?? '')),
            self::PROFILE_FIELD_MAIL => trim((string) ($profile[self::PROFILE_FIELD_MAIL] ?? '')),
            self::PROFILE_FIELD_FULLNAME => trim((string) ($profile[self::PROFILE_FIELD_FULLNAME] ?? '')),
            self::PROFILE_FIELD_LINKED_AT => trim((string) ($profile[self::PROFILE_FIELD_LINKED_AT] ?? '')),
            self::PROFILE_FIELD_LAST_LOGIN_AT => trim((string) ($profile[self::PROFILE_FIELD_LAST_LOGIN_AT] ?? '')),
            self::PROFILE_FIELD_AUTOLINK_BLOCKED => !empty($profile[self::PROFILE_FIELD_AUTOLINK_BLOCKED]) ? '1' : '0',
        ];

        $this->deleteCasProfileData($userId);

        $rows = [];

        foreach ($normalized as $key => $value) {
            if ($value === '' || ($key === self::PROFILE_FIELD_AUTOLINK_BLOCKED && $value !== '1')) {
                continue;
            }

            $rows[$key] = $value;
        }

        if ($rows === []) {
            return;
        }

        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__user_profiles'))
            ->columns([
                $db->quoteName('user_id'),
                $db->quoteName('profile_key'),
                $db->quoteName('profile_value'),
                $db->quoteName('ordering'),
            ]);

        $ordering = 1;

        foreach ($rows as $key => $value) {
            $query->values(
                implode(
                    ', ',
                    $query->bindArray(
                        [
                            $userId,
                            self::PROFILE_KEY_PREFIX . '.' . $key,
                            $value,
                            $ordering++,
                        ],
                        [
                            ParameterType::INTEGER,
                            ParameterType::STRING,
                            ParameterType::STRING,
                            ParameterType::INTEGER,
                        ]
                    )
                )
            );
        }

        $db->setQuery($query)->execute();
    }

    private function deleteCasProfileData(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userId')
            ->where($db->quoteName('profile_key') . ' LIKE :profileKey');

        $profileKey = self::PROFILE_KEY_PREFIX . '.%';
        $query->bind(':userId', $userId, ParameterType::INTEGER);
        $query->bind(':profileKey', $profileKey, ParameterType::STRING);

        $db->setQuery($query)->execute();
    }

    /**
     * @param   array{uid: string, email: string, fullname: string, type: string, language: string}  $casIdentity
     */
    private function storeCasLinkForUser(int $userId, array $casIdentity): void
    {
        $profile = $this->loadCasProfileData($userId);
        $now = Factory::getDate()->toSql();

        $profile[self::PROFILE_FIELD_UID] = $casIdentity['uid'];

        if ($casIdentity['email'] !== '') {
            $profile[self::PROFILE_FIELD_MAIL] = $casIdentity['email'];
        }

        if ($casIdentity['fullname'] !== '') {
            $profile[self::PROFILE_FIELD_FULLNAME] = $casIdentity['fullname'];
        }

        if (($profile[self::PROFILE_FIELD_LINKED_AT] ?? '') === '') {
            $profile[self::PROFILE_FIELD_LINKED_AT] = $now;
        }

        $profile[self::PROFILE_FIELD_LAST_LOGIN_AT] = $now;
        $profile[self::PROFILE_FIELD_AUTOLINK_BLOCKED] = $profile[self::PROFILE_FIELD_AUTOLINK_BLOCKED] ?? '0';

        $this->saveCasProfileData($userId, $profile);
    }

    private function loadUserById(int $userId): User
    {
        /** @var UserFactoryInterface $userFactory */
        $userFactory = Factory::getContainer()->get(UserFactoryInterface::class);

        return $userFactory->loadUserById($userId);
    }

    private function assertLocalUserIsActive(User $user): void
    {
        if ((int) $user->id <= 0) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_USER_NOT_FOUND'));
        }

        if ((int) ($user->block ?? 0) !== 0) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_USER_DISABLED'));
        }
    }

    private function assertLocalUserCanAccessAdministrator(User $user, bool $adminIntent): void
    {
        if (!$adminIntent) {
            return;
        }

        if (!$user->authorise('core.login.admin')) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_ADMIN_ACCESS_DENIED'));
        }
    }

    private function findAvailableUsername(string $casUid): string
    {
        $base = $this->sanitizeLocalUsername($casUid);
        $suffixBase = str_ends_with($base, self::USERNAME_SUFFIX) ? $base : $base . self::USERNAME_SUFFIX;
        $candidates = [$base];

        if ($suffixBase !== $base) {
            $candidates[] = $suffixBase;
        }

        for ($i = 2; $i <= self::MAX_USERNAME_ATTEMPTS; $i++) {
            $candidates[] = $suffixBase . $i;
        }

        foreach ($candidates as $candidate) {
            $candidate = substr($candidate, 0, 100);

            if ($candidate !== '' && (int) UserHelper::getUserId($candidate) === 0) {
                return $candidate;
            }
        }

        // Final fallback: allocate a short random-safe username instead of failing on
        // unexpected collisions across the conservative candidate list above.
        for ($i = 0; $i < 20; $i++) {
            $candidate = substr($suffixBase . '-' . bin2hex(random_bytes(4)), 0, 100);

            if ((int) UserHelper::getUserId($candidate) === 0) {
                $this->logFlowDebug('username.allocate.random_fallback', [
                    'cas_uid' => $casUid,
                    'candidate' => $candidate,
                ]);

                return $candidate;
            }
        }

        $this->logFlowDebug('username.allocate.exhausted', [
            'cas_uid' => $casUid,
            'base' => $base,
            'suffix_base' => $suffixBase,
        ]);

        throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_USERNAME_UNAVAILABLE'));
    }

    private function sanitizeLocalUsername(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? '';
        $sanitized = trim($sanitized, '.-_');

        if ($sanitized === '') {
            $sanitized = 'casauthsch';
        }

        return substr($sanitized, 0, 100);
    }

    private function appendUiErrorToUrl(string $target, string $message): string
    {
        if ($target === '' || $message === '') {
            return $target;
        }

        $encodedMessage = base64_encode($message);

        if ($encodedMessage === '') {
            return $target;
        }

        $uri = Uri::getInstance($target);
        $uri->setVar(self::UI_ERROR_QUERY_PARAM, $encodedMessage);

        return $uri->toString();
    }

    /**
     * @param   array<string, mixed>  $attributes
     * @param   array{uid: string, email: string, fullname: string, type: string, language: string}  $casIdentity
     */
    private function logCasAttributes(array $attributes, array $casIdentity): void
    {
        if (!(bool) $this->params->get('log_cas_attributes', 0)) {
            return;
        }

        $this->ensureLoggerConfigured();

        $payload = [
            'cas_uid' => $casIdentity['uid'],
            'resolved_email' => $casIdentity['email'],
            'resolved_fullname' => $casIdentity['fullname'],
            'service_url' => $this->getServiceUrl(),
            'attributes' => $attributes,
        ];

        $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($message === false) {
            $message = 'CAS attributes logging failed to encode payload.';
        }

        Log::add($message, Log::INFO, self::LOG_CATEGORY);
    }

    private function ensureLoggerConfigured(): void
    {
        if (self::$loggerConfigured) {
            return;
        }

        Log::addLogger([
            'text_file' => self::LOG_FILE,
            'text_entry_format' => '{DATETIME} {PRIORITY} {CLIENTIP} {MESSAGE}',
        ], Log::INFO, [self::LOG_CATEGORY, self::FLOW_LOG_CATEGORY]);

        self::$loggerConfigured = true;
    }

    /**
     * @param   array<string, mixed>  $context
     */
    private function logFlowDebug(string $stage, array $context = []): void
    {
        if (!(bool) $this->params->get('log_cas_attributes', 0)) {
            return;
        }

        $this->ensureLoggerConfigured();

        $message = json_encode([
            'stage' => $stage,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($message === false) {
            $message = $stage;
        }

        Log::add($message, Log::INFO, self::FLOW_LOG_CATEGORY);
    }

    /**
     * @param   array<string, mixed>  $attributes
     */
    private function assertAttributeAllowlists(array $attributes): void
    {
        $allowedUmdobject = $this->parseCsvList((string) $this->params->get('allowed_umdobject', ''));

        if ($allowedUmdobject !== [] && !$this->attributeMatchesAny($attributes, 'umdobject', $allowedUmdobject)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_UMDOBJECT'));
        }

        $allowedBusinessCategory = $this->parseCsvList((string) $this->params->get('allowed_business_category', ''));

        if ($allowedBusinessCategory !== [] && !$this->attributeMatchesAny($attributes, 'businessCategory', $allowedBusinessCategory)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_BUSINESS_CATEGORY'));
        }

        $allowedUnitCodes = $this->parseCsvList((string) $this->params->get('allowed_unit_codes', ''));

        if ($allowedUnitCodes !== []) {
            $unitCodeAttribute = trim((string) $this->params->get('unit_code_attribute', 'edupersonorgunitdn-gsnunitcode-extended'));

            if ($unitCodeAttribute === '') {
                $unitCodeAttribute = 'edupersonorgunitdn-gsnunitcode-extended';
            }

            if (!$this->unitCodeMatchesAny($attributes, $unitCodeAttribute, $allowedUnitCodes)) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_UNIT_CODE'));
            }
        }
    }

    /**
     * @param   array<string, string>  $userData
     */
    private function completeJoomlaLogin(CMSApplicationInterface $app, array $userData, bool $adminIntent = false): void
    {
        $dispatcher = $app->getDispatcher();
        $options = ['action' => $adminIntent ? 'core.login.admin' : 'core.login.site'];

        $this->logFlowDebug('login.local.dispatch', [
            'admin_intent' => $adminIntent,
            'action' => $options['action'],
            'username' => (string) ($userData['username'] ?? ''),
        ]);

        PluginHelper::importPlugin('user', null, true, $dispatcher);

        $loginEvent = new LoginEvent('onUserLogin', ['subject' => $userData, 'options' => $options]);
        $dispatcher->dispatch('onUserLogin', $loginEvent);

        $results = $loginEvent['result'] ?? [];

        $this->logFlowDebug('login.local.result', [
            'admin_intent' => $adminIntent,
            'action' => $options['action'],
            'results' => $results,
        ]);

        if (\in_array(false, $results, true)) {
            $dispatcher->dispatch('onUserLoginFailure', new LoginFailureEvent('onUserLoginFailure', [
                'subject' => $userData,
                'options' => $options,
            ]));

            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_LOCAL_LOGIN'));
        }

        $options['user'] = Factory::getUser();
        $options['responseType'] = $userData['type'];

        $dispatcher->dispatch('onUserAfterLogin', new AfterLoginEvent('onUserAfterLogin', [
            'options' => $options,
            'subject' => $userData,
        ]));
    }

    private function resolveLoginReturn(CMSApplicationInterface $app, bool $adminIntent = false): string
    {
        $input = $app->getInput();
        $session = $app->getSession();
        $isCasCallback = trim($input->getString('ticket', '')) !== '';

        if ($input->getInt('casauth_sch', 0) === 1 && $isCasCallback) {
            $stored = (string) $session->get(self::SESSION_RETURN, '');

            if ($stored !== '') {
                return $stored;
            }
        }

        $rawReturn = $input->getInputForRequestMethod()->get('return', '', 'BASE64');
        $decodedReturn = base64_decode((string) $rawReturn, true);
        $normalizedReturn = $this->normalizeReturn($app, $decodedReturn === false ? '' : $decodedReturn);

        if ($adminIntent) {
            $normalizedReturn = $this->normalizeAdministratorReturnTarget($normalizedReturn);

            return $normalizedReturn !== '' ? $normalizedReturn : $this->getAdministratorEntryPath();
        }

        $normalizedReturn = $this->sanitizeSsoReturnTarget($normalizedReturn);

        if ($normalizedReturn !== '') {
            return $normalizedReturn;
        }

        return Uri::root();
    }

    private function resolveLogoutReturn(CMSApplicationInterface $app): string
    {
        $rawReturn = $app->getInput()->getInputForRequestMethod()->get('return', '', 'BASE64');
        $decodedReturn = base64_decode((string) $rawReturn, true);
        $normalizedReturn = $this->normalizeReturn($app, $decodedReturn === false ? '' : $decodedReturn);
        $normalizedReturn = $this->sanitizeSsoReturnTarget($normalizedReturn);

        return $normalizedReturn !== '' ? $normalizedReturn : Uri::root();
    }

    private function normalizeReturn(CMSApplicationInterface $app, string $return): string
    {
        $return = trim($return);

        if ($return === '') {
            return '';
        }

        if (is_numeric($return)) {
            $itemId = (int) $return;
            $return = 'index.php?Itemid=' . $itemId;

            if (Multilanguage::isEnabled()) {
                $menuItem = $app->getMenu()->getItem($itemId);

                if ($menuItem && !empty($menuItem->language) && $menuItem->language !== '*') {
                    $return .= '&lang=' . $menuItem->language;
                }
            }

            return $return;
        }

        return Uri::isInternal($return) ? $return : '';
    }

    private function sanitizeSsoReturnTarget(string $return): string
    {
        $return = trim($return);

        if ($return === '') {
            return '';
        }

        if (
            stripos($return, 'option=com_users') !== false
            && stripos($return, 'view=login') !== false
        ) {
            return '';
        }

        return $return;
    }

    private function getServiceUrl(): string
    {
        $configured = trim((string) $this->params->get('service_url', ''));
        $serviceUrl = $configured !== ''
            ? $configured
            : rtrim(Uri::root(), '/') . '/index.php?option=com_users&view=login&casauth_sch=1';

        if (!filter_var($serviceUrl, FILTER_VALIDATE_URL) || stripos($serviceUrl, 'https://') !== 0) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_INVALID_SERVICE_URL'));
        }

        return $serviceUrl;
    }

    private function getServiceBaseUrl(string $serviceUrl): string
    {
        $parts = parse_url($serviceUrl);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_INVALID_SERVICE_URL'));
        }

        $baseUrl = $parts['scheme'] . '://' . $parts['host'];

        if (!empty($parts['port'])) {
            $baseUrl .= ':' . (int) $parts['port'];
        }

        return $baseUrl;
    }

    private function getLogoutUrl(): string
    {
        $logoutUrl = trim((string) $this->params->get('logout_url', 'https://sso-01.sch.gr/logout'));

        if (!filter_var($logoutUrl, FILTER_VALIDATE_URL) || stripos($logoutUrl, 'https://') !== 0) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CASAUTH_SCH_ERROR_INVALID_LOGOUT_URL'));
        }

        return $logoutUrl;
    }

    private function toAbsoluteUrl(string $target): string
    {
        if ($target === '') {
            return Uri::root();
        }

        if (filter_var($target, FILTER_VALIDATE_URL)) {
            return $target;
        }

        $routed = Route::_($target, false);

        if (filter_var($routed, FILTER_VALIDATE_URL)) {
            return $routed;
        }

        return rtrim(Uri::root(), '/') . '/' . ltrim($routed, '/');
    }

    /**
     * @param   array<string, mixed>  $attributes
     * @param   array<int, string>    $allowedValues
     */
    private function attributeMatchesAny(array $attributes, string $attributeName, array $allowedValues): bool
    {
        $values = $this->getAttributeValues($attributes, $attributeName);

        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            foreach ($allowedValues as $allowedValue) {
                if (strcasecmp($value, $allowedValue) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param   array<string, mixed>  $attributes
     * @param   array<int, string>    $allowedValues
     */
    private function unitCodeMatchesAny(array $attributes, string $attributeName, array $allowedValues): bool
    {
        $values = $this->getAttributeValues($attributes, $attributeName);

        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            $normalizedValue = $this->extractUnitCode($value);

            foreach ($allowedValues as $allowedValue) {
                if (strcasecmp($normalizedValue, $this->extractUnitCode($allowedValue)) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractUnitCode(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $parts = explode(';', $value);

        return trim((string) end($parts));
    }

    /**
     * @param   array<string, mixed>  $attributes
     *
     * @return  array<int, string>
     */
    private function getAttributeValues(array $attributes, string $name): array
    {
        if ($name === '') {
            return [];
        }

        $normalizedName = $this->normalizeAttributeName($name);

        foreach ($attributes as $attributeName => $value) {
            if ($this->normalizeAttributeName((string) $attributeName) !== $normalizedName) {
                continue;
            }

            $values = is_array($value) ? $value : [$value];

            return array_values(
                array_filter(
                    array_map(
                        static fn ($item): string => trim((string) $item),
                        $values
                    ),
                    static fn (string $item): bool => $item !== ''
                )
            );
        }

        return [];
    }

    private function normalizeAttributeName(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($name)));
    }

    /**
     * @param   array<string, mixed>  $attributes
     */
    private function getFirstAttributeValue(array $attributes, string $name): string
    {
        $values = $this->getAttributeValues($attributes, $name);

        return $values[0] ?? '';
    }

    /**
     * @return  array<int, string>
     */
    private function parseCsvList(string $value): array
    {
        return array_values(
            array_filter(
                array_map(
                    static fn (string $item): string => trim($item),
                    preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []
                ),
                static fn (string $item): bool => $item !== ''
            )
        );
    }

    /**
     * @param   array{uid: string, email: string, fullname: string, type: string, language: string, umdobject: string}  $casIdentity
     */
    private function resolveDefaultGroupIdForCasIdentity(array $casIdentity): int
    {
        $fallbackGroupId = (int) $this->params->get('default_group', 2);
        $umdobject = trim((string) ($casIdentity['umdobject'] ?? ''));
        $mappings = $this->getUmdobjectGroupMappings();

        if ($umdobject === '') {
            $this->logFlowDebug('group.resolve.no_umdobject', [
                'fallback_group_id' => $fallbackGroupId,
            ]);

            return $fallbackGroupId;
        }

        foreach ($mappings as $mapping) {
            if (strcasecmp($mapping['umdobject'], $umdobject) === 0) {
                $this->logFlowDebug('group.resolve.match', [
                    'umdobject' => $umdobject,
                    'group_id' => $mapping['group_id'],
                ]);

                return $mapping['group_id'];
            }
        }

        $this->logFlowDebug('group.resolve.fallback', [
            'umdobject' => $umdobject,
            'fallback_group_id' => $fallbackGroupId,
            'mappings' => $mappings,
        ]);

        return $fallbackGroupId;
    }

    /**
     * @return  array<int, array{umdobject: string, group_id: int}>
     */
    private function getUmdobjectGroupMappings(): array
    {
        $rawMappings = $this->params->get('umdobject_group_map', []);

        if (is_string($rawMappings)) {
            $decoded = json_decode($rawMappings, true);
            $rawMappings = is_array($decoded) ? $decoded : [];
        }

        if (is_object($rawMappings)) {
            $rawMappings = (array) $rawMappings;
        }

        if (!is_array($rawMappings)) {
            return [];
        }

        if (
            array_key_exists('umdobject', $rawMappings)
            || array_key_exists('group_id', $rawMappings)
        ) {
            $rawMappings = [$rawMappings];
        }

        $mappings = [];

        foreach ($rawMappings as $mapping) {
            if (is_object($mapping)) {
                $mapping = (array) $mapping;
            }

            if (!is_array($mapping)) {
                continue;
            }

            $umdobject = trim((string) ArrayHelper::getValue($mapping, 'umdobject', ''));
            $groupId = $this->normalizeMappedGroupId(ArrayHelper::getValue($mapping, 'group_id', 0));

            if ($umdobject === '' || $groupId <= 0) {
                continue;
            }

            $mappings[] = [
                'umdobject' => $umdobject,
                'group_id' => $groupId,
            ];
        }

        return $mappings;
    }

    private function normalizeMappedGroupId(mixed $value): int
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            foreach (['value', 'id', 0] as $candidateKey) {
                if (array_key_exists($candidateKey, $value)) {
                    return (int) $value[$candidateKey];
                }
            }

            return 0;
        }

        return (int) $value;
    }

    private function getUserIdFromFormData(mixed $data): int
    {
        if (\is_array($data)) {
            return ArrayHelper::getValue($data, 'id', 0, 'int');
        }

        if (\is_object($data) && isset($data->id)) {
            return (int) $data->id;
        }

        return 0;
    }

    /**
     * @param   array<string, mixed>  $data
     *
     * @return  array<string, mixed>|null
     */
    private function extractProfileFormDataFromSavePayload(array $data): ?array
    {
        if (isset($data[self::PROFILE_GROUP]) && \is_array($data[self::PROFILE_GROUP])) {
            return $data[self::PROFILE_GROUP];
        }

        $jformData = $this->getApplication()->getInput()->get('jform', [], 'array');

        if (isset($jformData[self::PROFILE_GROUP]) && \is_array($jformData[self::PROFILE_GROUP])) {
            return $jformData[self::PROFILE_GROUP];
        }

        return null;
    }

    private function canUseAdministratorSso(CMSApplicationInterface $app): bool
    {
        return (bool) $app->get('shared_session', '0');
    }

    private function shouldStartAdministratorLogin(CMSApplicationInterface $app): bool
    {
        if (
            !$app->isClient('administrator')
            || !$app->getIdentity()->guest
            || !(bool) $this->params->get('enable_admin_sso', 0)
            || !$this->canUseAdministratorSso($app)
        ) {
            return false;
        }

        return $app->getInput()->getInt(self::ADMIN_INTENT_QUERY_PARAM, 0) === 1;
    }

    private function handleAdministratorLoginStart(CMSApplicationInterface $app): void
    {
        $session = $app->getSession();
        $input = $app->getInput();
        $currentUri = Uri::getInstance();
        $currentUri->delVar(self::ADMIN_INTENT_QUERY_PARAM);
        $currentUri->delVar(self::UI_ERROR_QUERY_PARAM);

        $rawReturn = $input->getInputForRequestMethod()->get('return', '', 'BASE64');
        $decodedReturn = base64_decode((string) $rawReturn, true);
        $requestedReturn = $decodedReturn === false
            ? $currentUri->toString(['path', 'query', 'fragment'])
            : $decodedReturn;
        $returnTarget = $this->normalizeAdministratorReturnTarget($requestedReturn);

        $session->set(self::SESSION_ADMIN_INTENT, true);
        $session->set(self::SESSION_RETURN, $returnTarget);

        $redirectUrl = $this->getServiceUrl();

        $this->logFlowDebug('admin.intent.start', [
            'requested_return' => $requestedReturn,
            'stored_return' => $returnTarget,
            'redirect_url' => $redirectUrl,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);

        $app->redirect($redirectUrl);
        $app->close();
    }

    private function captureAdministratorIntent(CMSApplicationInterface $app): bool
    {
        $session = $app->getSession();
        $enabled = (bool) $this->params->get('enable_admin_sso', 0);
        $input = $app->getInput();
        $isCasCallback = trim($input->getString('ticket', '')) !== '';
        $isSiteCallback = $input->getInt('casauth_sch', 0) === 1;

        if (!$enabled) {
            $session->remove(self::SESSION_ADMIN_INTENT);

            return false;
        }

        if ($input->getInt(self::ADMIN_INTENT_QUERY_PARAM, 0) === 1) {
            $session->set(self::SESSION_ADMIN_INTENT, true);
        }

        $intent = (bool) $session->get(self::SESSION_ADMIN_INTENT, false);

        $this->logFlowDebug('admin.intent.capture', [
            'enabled' => $enabled,
            'is_callback' => $isCasCallback,
            'is_site_callback' => $isSiteCallback,
            'admin_param' => $input->getInt(self::ADMIN_INTENT_QUERY_PARAM, 0),
            'intent' => $intent,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);

        return $intent;
    }

    private function shouldHandleAdministratorLogout(CMSApplicationInterface $app): bool
    {
        if (!$app->isClient('administrator')) {
            return false;
        }

        $input = $app->getInput()->getInputForRequestMethod();
        $option = $input->getCmd('option');
        $task = $input->getCmd('task');
        $looksLikeLogoutRequest = $option === 'com_login' && $task === 'logout';

        if (!$looksLikeLogoutRequest) {
            return false;
        }

        $userId = (int) $app->getIdentity()->id;
        $sessionIsSso = (bool) $app->getSession()->get(self::SESSION_IS_SSO, false);
        $hasStoredLink = $userId > 0 && $this->hasStoredCasLink($userId);
        $tokenValid = Session::checkToken('request');

        $this->logFlowDebug('admin.logout.request', [
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'option' => $option,
            'task' => $task,
            'user_id' => $userId,
            'identity_guest' => $app->getIdentity()->guest,
            'session_is_sso' => $sessionIsSso,
            'has_stored_cas_link' => $hasStoredLink,
            'token_valid' => $tokenValid,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);

        if (!$tokenValid) {
            return false;
        }

        return $sessionIsSso || $hasStoredLink;
    }

    private function deliverAdministratorUiError(CMSApplicationInterface $app): void
    {
        if (!$app->isClient('administrator')) {
            return;
        }

        $input = $app->getInput();
        $rawMessage = $input->getString(self::UI_ERROR_QUERY_PARAM, '');

        if ($rawMessage !== '') {
            $decodedMessage = base64_decode(strtr($rawMessage, ' ', '+'), true);

            if ($decodedMessage !== false && trim($decodedMessage) !== '') {
                $app->getSession()->set(self::SESSION_UI_ERROR, trim($decodedMessage));
            }

            $currentUri = Uri::getInstance();
            $currentUri->delVar(self::UI_ERROR_QUERY_PARAM);
            $app->redirect($currentUri->toString());
            $app->close();
        }

        $message = trim((string) $app->getSession()->get(self::SESSION_UI_ERROR, ''));

        if ($message !== '') {
            $app->getSession()->remove(self::SESSION_UI_ERROR);
            $app->enqueueMessage($message, 'error');
        }
    }

    private function getAdministratorEntryPath(): string
    {
        $parts = parse_url($this->getServiceUrl());
        $path = (string) ($parts['path'] ?? '/index.php');
        $basePath = str_replace('\\', '/', dirname($path));

        if ($basePath === '.' || $basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }

        return rtrim($basePath, '/') . '/administrator/index.php';
    }

    private function normalizeAdministratorReturnTarget(string $return): string
    {
        $return = trim($return);

        if ($return === '') {
            return $this->getAdministratorEntryPath();
        }

        if (filter_var($return, FILTER_VALIDATE_URL)) {
            $parts = parse_url($return);

            if ($parts === false) {
                return $this->getAdministratorEntryPath();
            }

            $return = (string) ($parts['path'] ?? '');

            if (!empty($parts['query'])) {
                $return .= '?' . $parts['query'];
            }

            if (!empty($parts['fragment'])) {
                $return .= '#' . $parts['fragment'];
            }
        }

        if (!Uri::isInternal($return)) {
            return $this->getAdministratorEntryPath();
        }

        $normalized = ltrim($return, '/');

        if (!str_starts_with($normalized, 'administrator')) {
            return $this->getAdministratorEntryPath();
        }

        if (
            stripos($return, 'option=com_login') !== false
            || stripos($return, 'task=logout') !== false
        ) {
            return $this->getAdministratorEntryPath();
        }

        return $return;
    }

    private function isAdministratorProfileContext(): bool
    {
        $app = Factory::getApplication();

        return $app instanceof CMSApplicationInterface && $app->isClient('administrator');
    }
}
