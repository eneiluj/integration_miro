<?php
/**
 * Nextcloud - Miro
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Miro\Controller;

use DateTime;
use OCA\Miro\AppInfo\Application;
use OCA\Miro\Service\MiroAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

class ConfigController extends Controller {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var IL10N
	 */
	private $l;
	/**
	 * @var MiroAPIService
	 */
	private $miroAPIService;
	/**
	 * @var string|null
	 */
	private $userId;
	/**
	 * @var IInitialState
	 */
	private $initialStateService;

	public function __construct(string $appName,
		IRequest $request,
		IConfig $config,
		IURLGenerator $urlGenerator,
		IL10N $l,
		IInitialState $initialStateService,
		MiroAPIService $miroAPIService,
		?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->l = $l;
		$this->miroAPIService = $miroAPIService;
		$this->userId = $userId;
		$this->initialStateService = $initialStateService;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function isUserConnected(): DataResponse {
		$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');

		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$oauthPossible = $clientID !== '' && $clientSecret !== '';
		$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0');

		return new DataResponse([
			'connected' => $token !== '',
			'oauth_possible' => $oauthPossible,
			'use_popup' => ($usePopup === '1'),
			'client_id' => $clientID,
		]);
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		// revoke the token
		if (isset($values['token']) && $values['token'] === '') {
			$this->miroAPIService->revokeToken($this->userId);
		}

		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		$result = [];

		if (isset($values['token'])) {
			if ($values['token'] && $values['token'] !== '') {
				$result = $this->storeUserInfo();
			} else {
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'team_id');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'team_name');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
				$result['user_id'] = '';
				$result['user_name'] = '';
			}
			// if the token is set, cleanup refresh token and expiration date
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token_expires_at');
		}
		return new DataResponse($result);
	}

	/**
	 * set admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse(1);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $user_name
	 * @param string $user_id
	 * @return TemplateResponse
	 */
	public function popupSuccessPage(string $user_name, string $user_id): TemplateResponse {
		$this->initialStateService->provideInitialState('popup-data', ['user_name' => $user_name, 'user_id' => $user_id]);
		return new TemplateResponse(Application::APP_ID, 'popupSuccess', [], TemplateResponse::RENDER_AS_GUEST);
	}

	/**
	 * receive oauth code and get oauth access token
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $code
	 * @param string $state
	 * @return RedirectResponse
	 */
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

		// anyway, reset state
		$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_state');

		//		if ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
		if ($clientID && $clientSecret) {
			$redirect_uri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');
			$result = $this->miroAPIService->requestOAuthAccessToken([
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'grant_type' => 'authorization_code'
			], 'POST');
			if (isset($result['access_token'])) {
				$accessToken = $result['access_token'];
				$refreshToken = $result['refresh_token'] ?? '';
				if (isset($result['expires_in'])) {
					$nowTs = (new Datetime())->getTimestamp();
					$expiresAt = $nowTs + (int) $result['expires_in'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token_expires_at', $expiresAt);
				}
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
				// some info come with the token
				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $result['user_id']);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'team_id', $result['team_id']);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'scope', $result['scope']);

				$userInfo = $this->storeUserInfo();
				$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0') === '1';
				if ($usePopup) {
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute('integration_miro.config.popupSuccessPage', [
							'user_name' => $userInfo['user_name'] ?? '',
							'user_id' => $userInfo['user_id'] ?? '',
						])
					);
				} else {
					$oauthOrigin = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_origin');
					$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_origin');
					if ($oauthOrigin === 'settings') {
						return new RedirectResponse(
							$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
							'?miroToken=success'
						);
					} elseif ($oauthOrigin === 'app') {
						return new RedirectResponse(
							$this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index')
						);
					}
				}
			}
			$result = $this->l->t('Error getting OAuth access token. ' . $result['error']);
		} else {
			$result = $this->l->t('Error during OAuth exchanges');
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
			'?miroToken=error&message=' . urlencode($result)
		);
	}

	/**
	 * @return string
	 */
	private function storeUserInfo(): array {
		$info = $this->miroAPIService->request($this->userId, 'v1/oauth-token');
		if (isset(
			$info['team'], $info['team']['name'], $info['team']['id'],
			$info['user'], $info['user']['name'], $info['user']['id']
		)) {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $info['user']['id'] ?? '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['user']['name'] ?? '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'team_id', $info['team']['id'] ?? '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'team_name', $info['team']['name'] ?? '');

			return [
				'user_id' => $info['user']['id'] ?? '',
				'user_name' => $info['user']['name'] ?? '',
			];
		} else {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
			return [
				'user_id' => '',
				'user_name' => '',
			];
		}
	}
}
