<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Julien Veyssier
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Miro\Search;

use OCA\Miro\Service\MiroAPIService;
use OCA\Miro\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;

class MiroSearchBoardsProvider implements IProvider {

	/** @var IAppManager */
	private $appManager;

	/** @var IL10N */
	private $l10n;

	/** @var IURLGenerator */
	private $urlGenerator;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var MiroAPIService
	 */
	private $service;
	/**
	 * @var IDateTimeFormatter
	 */
	private $dateTimeFormatter;
	/**
	 * @var IDateTimeZone
	 */
	private $dateTimeZone;

	/**
	 * CospendSearchProvider constructor.
	 *
	 * @param IAppManager $appManager
	 * @param IL10N $l10n
	 * @param IConfig $config
	 * @param IURLGenerator $urlGenerator
	 * @param MiroAPIService $service
	 */
	public function __construct(IAppManager $appManager,
								IL10N $l10n,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IDateTimeFormatter $dateTimeFormatter,
								IDateTimeZone $dateTimeZone,
								MiroAPIService $service) {
		$this->appManager = $appManager;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->service = $service;
		$this->dateTimeFormatter = $dateTimeFormatter;
		$this->dateTimeZone = $dateTimeZone;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'miro-search-messages';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l10n->t('Miro messages');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (strpos($route, Application::APP_ID . '.') === 0) {
			// Active app, prefer Miro results
			return -1;
		}

		return 20;
	}

	/**
	 * @inheritDoc
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = $query->getLimit();
		$term = $query->getTerm();
		$offset = $query->getCursor();
		$offset = $offset ? intval($offset) : 0;

		$accessToken = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'token');
		$url = 'https://miro.com';
		$searchBoardsEnabled = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'search_messages_enabled', '0') === '1';
		if ($accessToken === '' || !$searchBoardsEnabled) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$issues = $this->service->searchMessages($user->getUID(), $url, $term, $offset, $limit);
		if (isset($searchResult['error'])) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$formattedResults = array_map(function (array $entry) use ($url): MiroSearchResultEntry {
			$finalThumbnailUrl = $this->getThumbnailUrl($entry);
			return new MiroSearchResultEntry(
				$finalThumbnailUrl,
				$this->getMainText($entry),
				$this->getSubline($entry),
				$this->getLinkToMiro($entry, $url),
				$finalThumbnailUrl === '' ? 'icon-miro-search-fallback' : '',
				true
			);
		}, $issues);

		return SearchResult::paginated(
			$this->getName(),
			$formattedResults,
			$offset + $limit
		);
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getMainText(array $entry): string {
		return $entry['message'];
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getSubline(array $entry): string {
		return $this->l10n->t('%s in #%s at %s', [$entry['user_name'], $entry['channel_name'], $this->getFormattedDate($entry['create_at'])]);
	}

	protected function getFormattedDate(int $timestamp): string {
		// return (new DateTime())->setTimestamp((int) ($timestamp / 1000))->format('Y-m-d H:i:s');
		return $this->dateTimeFormatter->formatDateTime((int) ($timestamp / 1000), 'long', 'short', $this->dateTimeZone->getTimeZone());
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getLinkToMiro(array $entry, string $url): string {
		return $url . '/' . $entry['team_name'] . '/channels/' . $entry['channel_name'];
	}

	/**
	 * @param array $entry
	 * @param string $thumbnailUrl
	 * @return string
	 */
	protected function getThumbnailUrl(array $entry): string {
		$userId = $entry['user_id'] ?? '';
		return $userId
			? $this->urlGenerator->linkToRoute('integration_miro.miroAPI.getUserAvatar', ['userId' => $userId])
			: '';
	}
}
