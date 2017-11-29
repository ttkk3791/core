<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OC\User;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\ILogger;
use OCP\User\IProvidesEMailBackend;
use OCP\User\IProvidesExtendedSearchBackend;
use OCP\User\IProvidesQuotaBackend;
use OCP\UserInterface;

/**
 * Class SyncService
 *
 * All users in a user backend are transferred into the account table.
 * In case a user is know all preferences will be transferred from the table
 * oc_preferences into the account table.
 *
 * @package OC\User
 */
class SyncService {

	/** @var IConfig */
	private $config;
	/** @var ILogger */
	private $logger;
	/** @var AccountMapper */
	private $mapper;

	/**
	 * SyncService constructor.
	 *
	 * @param IConfig $config
	 * @param ILogger $logger
	 * @param AccountMapper $mapper
	 */
	public function __construct(IConfig $config,
								ILogger $logger,
								AccountMapper $mapper) {
		$this->config = $config;
		$this->logger = $logger;
		$this->mapper = $mapper;
	}

	/**
	 * @param UserInterface $backend the backend to check
	 * @param \Closure $callback is called for every user to allow progress display
	 * @return array
	 */
	public function getNoLongerExistingUsers(UserInterface $backend, \Closure $callback) {
		// detect no longer existing users
		$toBeDeleted = [];
		$backendClass = get_class($backend);
		$this->mapper->callForAllUsers(function (Account $a) use (&$toBeDeleted, $backend, $backendClass, $callback) {
			if ($a->getBackend() === $backendClass) {
				if (!$backend->userExists($a->getUserId())) {
					$toBeDeleted[] = $a->getUserId();
				}
			}
			$callback($a);
		}, '', false);

		return $toBeDeleted;
	}

	/**
	 * @param UserInterface $backend to sync
	 * @param \Closure $callback is called for every user to progress display
	 */
	public function run(UserInterface $backend, \Closure $callback) {
		$limit = 500;
		$offset = 0;
		$backendClass = get_class($backend);
		do {
			$users = $backend->getUsers('', $limit, $offset);

			// update existing and insert new users
			foreach ($users as $uid) {
				try {
					$a = $this->mapper->getByUid($uid);
					if ($a->getBackend() !== $backendClass) {
						$this->logger->warning(
							"User <$uid> already provided by another backend({$a->getBackend()} != $backendClass), skipping.",
							['app' => self::class]
						);
						continue;
					}
					$a = $this->syncAccount($a, $backend, $uid);
					$this->mapper->update($a);
				} catch(DoesNotExistException $ex) {
					$a = $this->createNewAccount($backendClass, $uid);
					$this->syncAccount($a, $backend, $uid);
					$this->mapper->insert($a);
				}
				// clean the user's preferences
				$this->cleanPreferences($uid);

				// call the callback
				$callback($uid);
			}
			$offset += $limit;
		} while(count($users) >= $limit);
	}

	/**
	 * @param Account $a
	 * @param UserInterface $backend of the user
	 * @param string $uid
	 * @return Account
	 */
	public function syncAccount(Account $a, UserInterface $backend, $uid) {
		list($hasKey, $value) = $this->readUserConfig($uid, 'core', 'enabled');
		if ($hasKey) {
			$a->setState(($value === 'true') ? Account::STATE_ENABLED : Account::STATE_DISABLED);
		}
		list($hasKey, $value) = $this->readUserConfig($uid, 'login', 'lastLogin');
		if ($hasKey) {
			$a->setLastLogin($value);
		}
		if ($backend instanceof IProvidesEMailBackend) {
			$a->setEmail($backend->getEMailAddress($uid));
		} else {
			list($hasKey, $value) = $this->readUserConfig($uid, 'settings', 'email');
			if ($hasKey) {
				$a->setEmail($value);
			}
		}
		if ($backend instanceof IProvidesQuotaBackend) {
			$quota = $backend->getQuota($uid);
			if ($quota !== null) {
				$a->setQuota($quota);
			}
		} else {
			list($hasKey, $value) = $this->readUserConfig($uid, 'files', 'quota');
			if ($hasKey) {
				$a->setQuota($value);
			}
		}
		$home = false;
		if ($backend->implementsActions(\OC_User_Backend::GET_HOME)) {
			$home = $backend->getHome($uid);
		}
		if (!is_string($home) || substr($home, 0, 1) !== '/') {
			$home = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . "/$uid";
			$this->logger->warning(
				"User backend ".get_class($backend)." provided no home for <$uid>, using <$home>.",
				['app' => self::class]
			);
		}
		// This will set the home if not provided by the backend
		$a->setHome($home);

		if ($backend->implementsActions(\OC_User_Backend::GET_DISPLAYNAME)) {
			//TODO IConsumesDisplayNameBackend for setDisplayName?
			$a->setDisplayName($backend->getDisplayName($uid));
		}
		// Check if backend supplies an additional search string
		if ($backend instanceof IProvidesExtendedSearchBackend) {
			$a->setSearchTerms($backend->getSearchTerms($uid));
		}
		return $a;
	}

	/**
	 * @param $uid
	 * @param UserInterface $backend
	 * @return Account
	 * @throws \InvalidArgumentException
	 */
	public function createOrSyncAccount($uid, $backend) {
		// Try to find the account based on the uid
		try {
			$account = $this->mapper->getByUid($uid);
		} catch (DoesNotExistException $e) {
			// Create a new account for this uid and backend pairing and sync
			$account = $this->createNewAccount(get_class($backend), $uid);
		}

		// Check the backend is the same
		if($account->getBackend() != get_class($backend)) {
			// Then we have a uid collision
			$this->logger->info("Uid collision when uid: $uid attempted to login");
			throw new \InvalidArgumentException('Uid collision1');
		}

		// The account exists, sync
		$account = $this->syncAccount($account, $backend, $uid);
		if($account->getId() === null) {
			// New account, insert
			$this->mapper->insert($account);
		} else {
			$this->mapper->update($account);
		}
		return $account;
	}

	/**
	 * @param string $backend of the user
	 * @param string $uid of the user
	 * @return Account
	 */
	public function createNewAccount($backend, $uid) {
		$this->logger->info("Creating new account with UID $uid and backend $backend");
		$a = new Account();
		$a->setUserId($uid);
		$a->setState(Account::STATE_ENABLED);
		$a->setBackend($backend);
		return $a;
	}

	/**
	 * @param string $uid
	 * @param string $app
	 * @param string $key
	 * @return array
	 */
	private function readUserConfig($uid, $app, $key) {
		$keys = $this->config->getUserKeys($uid, $app);
		if (in_array($key, $keys)) {
			$enabled = $this->config->getUserValue($uid, $app, $key);
			return [true, $enabled];
		}
		return [false, null];
	}

	/**
	 * @param string $uid
	 */
	private function cleanPreferences($uid) {
		$this->config->deleteUserValue($uid, 'core', 'enabled');
		$this->config->deleteUserValue($uid, 'login', 'lastLogin');
		$this->config->deleteUserValue($uid, 'settings', 'email');
		$this->config->deleteUserValue($uid, 'files', 'quota');
	}

}
