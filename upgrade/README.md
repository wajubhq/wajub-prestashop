# Upgrade scripts

PrestaShop runs `upgrade-X.Y.Z.php` for every version between the one installed and the one uploaded, oldest first, when the merchant upgrades the module. Each defines `upgrade_module_X_Y_Z($module)`, which returns `true` once done; `false` stops the upgrade, and PrestaShop says so.

A version needs a script only when it changes what `install()` set up:

- **a hook added** to `Wajub::HOOKS`: register the list again (a hook already registered is left as it is):

  ```php
  function upgrade_module_1_1_0($module)
  {
      return $module->registerHook(Wajub::HOOKS);
  }
  ```

- **a table added**: `Wajub\PrestaShop\Install\Schema::create()` (every statement is `CREATE TABLE IF NOT EXISTS`);
- **a column added** to a table: add it in `Schema::statements()` and in `Schema::addedColumns()`, which `create()` adds to a table an earlier version made; then call `Schema::create()`. A table kept at uninstall (`Schema::KEPT`) is found as an earlier version left it by a later install, which runs no upgrade script: `create()` must bring it up to date too;
- **a setting renamed or added with a value of its own**: write it for every scope that saved the old one (`ConfigStore::savedAt()` lists them).

- **a front controller added** to `$this->controllers`: its `meta` page, which an install makes (`Module::installControllers()`), as `upgrade-1.0.1.php` does for `recount`;
- **a module position on a hook**: `upgrade-1.0.1.php` puts the module last on the refund forms' hook (`Wajub::keepLastOnRefundForms()`), after registering `Wajub::HOOKS` again for `actionModuleRegisterHookAfter`, which keeps it there;
- **an event added** to `WebhookRegistration::EVENTS`: `$module->subscribeWebhookEvents()`, which gives every endpoint a scope registered the events it lacks, and never fails the upgrade for Wajub not answering (`upgrade-1.0.1.php`).

Every script starts with the license header and `if (!defined('_PS_VERSION_')) { exit; }`, as every PHP file of the module. Version 1.0.0, the first, has none.
