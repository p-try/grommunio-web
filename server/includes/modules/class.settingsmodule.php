<?php

/**
 * Settings Module.
 */
class SettingsModule extends Module {
	/**
	 * Constructor.
	 *
	 * @param int   $id   unique id
	 * @param array $data list of all actions
	 */
	public function __construct($id, $data) {
		parent::__construct($id, $data);
	}

	/**
	 * Executes all the actions in the $data variable.
	 */
	#[Override]
	public function execute() {
		foreach ($this->data as $actionType => $action) {
			if (isset($actionType)) {
				try {
					$storeIdHex = $this->getStoreIdHexFromAction($action);
					switch ($actionType) {
						case "retrieveAll":
							$this->retrieveAll($actionType, $storeIdHex);
							break;

						case "set":
							if (isset($action["setting"])) {
								$this->set($action["setting"], false, $storeIdHex);
							}
							if (isset($action["persistentSetting"])) {
								$this->set($action["persistentSetting"], true, $storeIdHex);
							}
							break;

						case "delete":
						case "reset":
							$store = $this->openStoreFromHex($storeIdHex);
							if ($store === false) {
								$store = $GLOBALS['mapisession']->getDefaultMessageStore();
							}
							$inbox = mapi_msgstore_getreceivefolder($store);
							mapi_deleteprops($inbox, [PR_ADDITIONAL_REN_ENTRYIDS_EX, PR_ADDITIONAL_REN_ENTRYIDS]);
							$this->delete($action["setting"], $storeIdHex);
							break;

						default:
							$this->handleUnknownActionType($actionType);
					}
				}
				catch (MAPIException|SettingsException $e) {
					$this->processException($e, $actionType);
				}
			}
		}
	}

	/**
	 * Function will retrieve all settings stored in PR_EC_WEBACCESS_SETTINGS_JSON property
	 * if property is not defined then it will return generate SettingsException but silently ignores it.
	 *
	 * @param mixed $type
	 */
	public function retrieveAll($type, $storeIdHex = null) {
		$data = $GLOBALS['settings']->get(null, null, false, $storeIdHex);

		$this->addActionData($type, $data);
		$GLOBALS["bus"]->addData($this->getResponseData());
	}

	/**
	 * Function will set a value of a setting indicated by path of the setting.
	 *
	 * @param mixed $settings   Object containing a $path and $value of the setting
	 *                          which must be modified
	 * @param bool  $persistent If true the settings will be stored in the persistent settings
	 *                          as opposed to the normal settings
	 */
	public function set($settings, $persistent = false, $storeIdHex = null) {
		if (isset($settings)) {
			// we will set the settings but wait with saving until the entire batch has been applied.
			if (is_array($settings)) {
				foreach ($settings as $setting) {
					if (isset($setting['path'], $setting['value'])) {
						if ((bool) $persistent) {
							$GLOBALS['settings']->setPersistent($setting['path'], $setting['value'], false, $storeIdHex);
						}
						else {
							$GLOBALS['settings']->set($setting['path'], $setting['value'], false, false, $storeIdHex);
						}
					}
				}
			}
			elseif (isset($settings['path'], $settings['value'])) {
				if ((bool) $persistent) {
					$GLOBALS['settings']->setPersistent($settings['path'], $settings['value'], false, $storeIdHex);
				}
				else {
					$GLOBALS['settings']->set($settings['path'], $settings['value'], false, false, $storeIdHex);
				}
			}

			// Finally save the settings, this can throw exception when it fails saving settings
			if ((bool) $persistent) {
				$GLOBALS['settings']->savePersistentSettings($storeIdHex);
			}
			else {
				$GLOBALS['settings']->saveSettings($storeIdHex);
			}

			// send success notification to client
			$this->sendFeedback(true);
		}
	}

	/**
	 * Function will delete a setting indicated by setting path.
	 *
	 * @param $path string/array path of the setting that needs to be deleted
	 */
	public function delete($path, $storeIdHex = null) {
		if (isset($path)) {
			// we will delete the settings but wait with saving until the entire batch has been applied.
			if (is_array($path)) {
				foreach ($path as $item) {
					$GLOBALS['settings']->delete($item, false, $storeIdHex);
				}
			}
			else {
				$GLOBALS['settings']->delete($path, false, $storeIdHex);
			}

			// Finally save the settings, this can throw exception when it fails saving settings
			$GLOBALS['settings']->saveSettings($storeIdHex);

			// send success notification to client
			$this->sendFeedback(true);
		}
	}

	/**
	 * Extract HEX store entryid from an action payload (if provided).
	 */
	private function getStoreIdHexFromAction($action) {
		if (isset($action["store_entryid"])) {
			$se = $action["store_entryid"];
			if (is_array($se) && count($se) > 0) {
				$se = $se[0];
			}
			if (is_string($se) && ctype_xdigit((string) $se)) {
				return $se;
			}
		}
		return null; // default store
	}

	/**
	 * Try to open a message store from HEX entryid. Returns false on failure.
	 */
	private function openStoreFromHex($storeIdHex) {
		try {
			if ($storeIdHex && is_string($storeIdHex) && ctype_xdigit((string) $storeIdHex)) {
				return $GLOBALS['mapisession']->openMessageStore(hex2bin((string) $storeIdHex));
			}
		}
		catch (Exception) {
		}
		return false;
	}
}
