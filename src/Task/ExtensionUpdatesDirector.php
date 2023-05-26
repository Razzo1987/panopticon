<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\Task;

defined('AKEEBA') || die;

use Akeeba\Panopticon\Library\Task\AbstractCallback;
use Akeeba\Panopticon\Library\Task\Attribute\AsTask;
use Akeeba\Panopticon\Library\Task\Status;
use Akeeba\Panopticon\Library\Version\Version;
use Akeeba\Panopticon\Model\Site;
use Akeeba\Panopticon\Model\Sysconfig;
use Awf\Mvc\Model;
use Awf\Registry\Registry;
use Awf\Utils\ArrayHelper;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

#[AsTask(
	name: 'extensionupdatesdirector',
	description: 'PANOPTICON_TASKTYPE_EXTENSIONUPDATESDIRECTOR'
)]
class ExtensionUpdatesDirector extends AbstractCallback implements LoggerAwareInterface
{
	use LoggerAwareTrait;
	use EnqueueExtensionUpdateTrait;

	public function __invoke(object $task, Registry $storage): int
	{
		$task->params ??= new Registry();
		$params       = ($task->params instanceof Registry) ? $task->params : new Registry($task->params);

		$limitStart = (int)$storage->get('limitStart', 0);
		$limit      = (int)$storage->get('limit', $params->get('limit', 100));
		$force      = (bool)$storage->get('force', $params->get('force', false));
		$filterIDs  = $storage->get('filter.ids', $params->get('ids', []));

		/**
		 * Reasoning behind this code:
		 *
		 * “The correct way to use LOCK TABLES and UNLOCK TABLES with transactional tables, such as InnoDB tables, is to
		 * begin a transaction with SET autocommit = 0 (not START TRANSACTION) followed by LOCK TABLES, and to not call
		 * UNLOCK TABLES until you commit the transaction explicitly.”
		 *
		 * This is meant to avoid deadlocks.
		 *
		 * @see https://dev.mysql.com/doc/refman/5.7/en/lock-tables.html
		 */
		// Lock the #__sites and #__tasks tables
		$db = $this->container->db;
		$db->setQuery('SET autocommit = 0')->execute();
		$sql = 'LOCK TABLES ' . $db->quoteName('#__sites') . ' WRITE, '
			. $db->quoteName('#__tasks') . ' WRITE, '
			. $db->quoteName('#__queue') . ' WRITE';
		$db->setQuery($sql)->execute();

		$siteIDs = $this->getSiteIDs($limitStart, $limit, $force, $filterIDs);

		if (empty($siteIDs))
		{
			$this->logger->info('No more sites in need of automatic extension updates.');

			$db->unlockTables();
			$db->setQuery('SET autocommit = 1')->execute();

			return Status::OK->value;
		}

		$this->logger->info(sprintf(
			'Found a further %d site(s) to process for automatic extension updates.',
			count($siteIDs)
		));

		// Set `extensions.lastAutoUpdateEnqueueTime` to current timestamp for all sites to be processed
		$query = $db->getQuery(true)
					->update($db->quoteName('#__sites'));
		$query->set(
			$db->quoteName('config') . '= JSON_SET(' . $db->quoteName('config') . ',' .
			$db->quote('$.extensions.lastAutoUpdateEnqueueTime') . ',' . $db->quote(time()) . ')'
		)
			  ->where($db->quoteName('id') . ' IN(' . implode(',', $siteIDs) . ')');
		$db->setQuery($query)->execute();

		// End the transaction
		$db->setQuery('COMMIT')->execute();
		$db->unlockTables();
		$db->setQuery('SET autocommit = 1')->execute();

		/** @var Site $site */
		$site = Model::getTmpInstance('', 'Site', $this->container);

		/** @var Sysconfig $sysConfigModel */
		$sysConfigModel             = Model::getTmpInstance('', 'Sysconfig', $this->container);
		$globalExtUpdatePreferences = $sysConfigModel->getExtensionPreferencesAndMeta(null);
		$defaultExtUpdatePreference = $this->container->appConfig->get('tasks_extupdate_install', 'none');


		foreach ($siteIDs as $id)
		{
			$site->reset();

			try
			{
				$site->findOrFail($id);
			}
			catch (\RuntimeException $e)
			{
				$this->logger->notice(
					sprintf('Site %d not found; skipping.', $id)
				);

				continue;
			}

			$siteConfig =
				($site->getFieldValue('config') instanceof Registry) ? $site->getFieldValue('config')
					: new Registry($site->getFieldValue('config') ?? '{}');
			$extensions = (array)$siteConfig->get('extensions.list');

			if (empty($extensions))
			{
				$this->logger->debug(
					sprintf(
						'There are no known extensions for site #%d (%s)',
						$site->id, $site->name
					)
				);

				continue;
			}

			$extensionsWithMeta = $sysConfigModel->getExtensionPreferencesAndMeta($site->id);

			$extensions = array_filter(
				$extensions,
				function ($item) use (
					$globalExtUpdatePreferences, $defaultExtUpdatePreference, $sysConfigModel, $extensionsWithMeta
				): bool
				{
					// Can't update an extension without any update sites to its name, right?
					if (!$item->hasUpdateSites)
					{
						return false;
					}

					// Can't update if we need a Download Key which has not been provided or is invalid
					if (($item->downloadkey?->supported ?? false) && !($item->downloadkey?->valid ?? false))
					{
						return false;
					}

					/**
					 * Can't update an extension without version information, or when the installed version is newer
					 * than the ostensibly latest version.
					 */
					$currentVersion = $item->version?->current;
					$newVersion     = $item->version?->new;

					if (empty($currentVersion) || empty($newVersion) || $currentVersion === $newVersion
						|| version_compare($currentVersion, $newVersion, 'ge'))
					{
						return false;
					}

					// Get the extension shortname (key)
					$key = $sysConfigModel
						->getExtensionShortname($item->type, $item->element, $item->folder, $item->client_id);

					// Parse the update preference
					$effectivePreference =
						$extensionsWithMeta[$key]?->preference ?: $globalExtUpdatePreferences[$key]?->preference;
					$effectivePreference = $effectivePreference ?: $defaultExtUpdatePreference;

					if ($effectivePreference === 'none')
					{
						return false;
					}

					if ($effectivePreference === 'major')
					{
						return true;
					}

					$vOld = Version::create($currentVersion);
					$vNew = Version::create($newVersion);

					return match ($effectivePreference)
					{
						default => false,
						'minor' => $vOld->major() === $vNew->major(),
						'patch' => $vOld->versionFamily() === $vNew->versionFamily(),
					};
				}
			);

			if (empty($extensions))
			{
				$this->logger->debug(
					sprintf(
						'There are no extensions which need to be updated on site #%d (%s)',
						$site->id, $site->name
					)
				);

				continue;
			}

			// Enqueue necessary updates
			$numExtensions = 0;

			foreach ($extensions as $item)
			{
				$added = $this->enqueueExtensionUpdate($site, $item->extension_id);

				if ($added)
				{
					$numExtensions++;
				}
			}

			// Add/enable joomlaupdate task for this site if any updates were necessary
			if ($numExtensions > 0)
			{
				if ($numExtensions === 1)
				{
					$this->logger->debug(
						sprintf(
							'1 extension was queued for automatic updates on site #%d (%s)',
							$site->id, $site->name
						)
					);
				}
				else
				{
					$this->logger->debug(
						sprintf(
							'%d extensions were queued for automatic updates on site #%d (%s)',
							$numExtensions, $site->id, $site->name
						)
					);
				}

				$this->scheduleExtensionsUpdateForSite($site, $this->container);

				continue;
			}

			$this->logger->debug(
				sprintf(
					'No extension were queued for automatic updates on site #%d (%s) — all extensions were already queued up',
					$site->id, $site->name
				)
			);
		}

		$storage->set('limitStart', $limitStart + $limit);

		return Status::WILL_RESUME->value;
	}

	private function getSiteIDs(int $limitStart = 0, int $limit = 100, bool $force = false, array $ids = []): array
	{
		$db    = $this->container->db;
		$query = $db->getQuery(true)
					->select($db->quoteName('id'))
					->from($db->quoteName('#__sites'));
		$query->where([
			$db->quoteName('enabled') . ' = 1',
		]);

		if (!$force)
		{
			// Only allow update enqueueing to run every 30'
			$timeLimit = time() - 1800;

			//   AND (
			//        `config` -> '$.extensions.lastAutoUpdateEnqueueTime' IS NULL
			//        OR `config` -> '$.extensions.lastAutoUpdateEnqueueTime' <= 1234567890
			//    )
			$query->extendWhere('AND', [
				$query->jsonPointer('config', '$.extensions.lastAutoUpdateEnqueueTime') . ' IS NULL',
				$query->jsonPointer('config', '$.extensions.lastAutoUpdateEnqueueTime') . ' <= ' .
				$db->quote($timeLimit),
			], 'OR');
		}

		$ids = ArrayHelper::toInteger($ids);
		$ids = array_filter($ids, fn($x) => !empty($x));

		if (!empty($ids))
		{
			$query->where(
				$db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'
			);
		}

		return $db->setQuery($query, $limitStart, $limit)->loadColumn() ?: [];
	}
}