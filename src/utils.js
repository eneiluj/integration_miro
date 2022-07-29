import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

export function Timer(callback, mydelay) {
	let timerId
	let start
	let remaining = mydelay

	this.pause = function() {
		window.clearTimeout(timerId)
		remaining -= new Date() - start
	}

	this.resume = function() {
		start = new Date()
		window.clearTimeout(timerId)
		timerId = window.setTimeout(callback, remaining)
	}

	this.resume()
}

let mytimer = 0
export function delay(callback, ms) {
	return function() {
		const context = this
		const args = arguments
		clearTimeout(mytimer)
		mytimer = setTimeout(function() {
			callback.apply(context, args)
		}, ms || 0)
	}
}

export function oauthConnect(clientId, oauthOrigin, usePopup = false) {
	const redirectUri = window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_miro/oauth-redirect')

	// const oauthState = Math.random().toString(36).substring(3)
	const requestUrl = 'https://miro.com/oauth/authorize'
		+ '?client_id=' + encodeURIComponent(clientId)
		+ '&redirect_uri=' + encodeURIComponent(redirectUri)
		+ '&response_type=code'
	// + '&state=' + encodeURIComponent(oauthState)
	// + '&scope=' + encodeURIComponent('read_user read_api read_repository')

	const req = {
		values: {
			// oauth_state: oauthState,
			redirect_uri: redirectUri,
			oauth_origin: usePopup ? undefined : oauthOrigin,
		},
	}
	const url = generateUrl('/apps/integration_miro/config')
	return new Promise((resolve, reject) => {
		axios.put(url, req).then((response) => {
			if (usePopup) {
				const ssoWindow = window.open(
					requestUrl,
					t('integration_miro', 'Sign in with Miro'),
					'toolbar=no, menubar=no, width=600, height=700')
				ssoWindow.focus()
				window.addEventListener('message', (event) => {
					console.debug('Child window message received', event)
					resolve(event.data)
				})
			} else {
				window.location.replace(requestUrl)
			}
		}).catch((error) => {
			showError(
				t('integration_miro', 'Failed to save Miro OAuth state')
				+ ': ' + (error.response?.request?.responseText ?? '')
			)
			console.error(error)
		})
	})
}

export function oauthConnectConfirmDialog() {
	return new Promise((resolve, reject) => {
		const settingsLink = generateUrl('/settings/user/connected-accounts')
		const linkText = t('integration_miro', 'Connected accounts')
		const settingsHtmlLink = `<a href="${settingsLink}" class="external">${linkText}</a>`
		OC.dialogs.message(
			t('integration_miro', 'You need to connect before using the Miro integration.')
			+ '<br><br>'
			+ t('integration_miro', 'Do you want to connect to Miro?')
			+ '<br><br>'
			+ t(
				'integration_miro',
				'You can change Miro integration settings in the {settingsHtmlLink} section of your personal settings.',
				{ settingsHtmlLink },
				null,
				{ escape: false }
			),
			t('integration_miro', 'Connect to Miro'),
			'none',
			{
				type: OC.dialogs.YES_NO_BUTTONS,
				confirm: t('integration_miro', 'Connect'),
				confirmClasses: 'success',
				cancel: t('integration_miro', 'Cancel'),
			},
			(result) => {
				resolve(result)
			},
			true,
			true,
		)
	})
}

export function humanFileSize(bytes, approx = false, si = false, dp = 1) {
	const thresh = si ? 1000 : 1024

	if (Math.abs(bytes) < thresh) {
		return bytes + ' B'
	}

	const units = si
		? ['kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
		: ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB']
	let u = -1
	const r = 10 ** dp

	do {
		bytes /= thresh
		++u
	} while (Math.round(Math.abs(bytes) * r) / r >= thresh && u < units.length - 1)

	if (approx) {
		return Math.floor(bytes) + ' ' + units[u]
	} else {
		return bytes.toFixed(dp) + ' ' + units[u]
	}
}
