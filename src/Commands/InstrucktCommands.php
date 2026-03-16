<?php

declare(strict_types=1);

namespace Drupal\instruckt_drupal\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\key\KeyRepositoryInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Instruckt Drupal setup.
 */
class InstrucktCommands extends DrushCommands {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
  ) {
    parent::__construct();
  }

  /**
   * Set up Instruckt MCP authentication.
   *
   * Grants 'use mcp server' to a role, creates an auth token key, and
   * configures mcp.settings token authentication. Safe to re-run.
   *
   * @param array $options An associative array of options.
   *
   * @command instruckt:setup
   * @option role  Drupal role to grant 'use mcp server'
   * @option user  UID or username MCP requests run as
   * @option key-id  Machine name for the auth token key entity
   * @usage drush instruckt:setup
   * @usage drush instruckt:setup --role=editor --user=2 --key-id=my_mcp_key
   * @aliases instruckt-setup
   */
  public function setup(array $options = [
    'role'   => 'authenticated',
    'user'   => '1',
    'key-id' => 'instruckt_mcp_token',
  ]): void {
    // 0. Bootstrap private filesystem if not yet configured.
    if ($this->streamWrapperManager->isValidScheme('private')) {
      $this->output()->writeln('[skip] Private filesystem already configured.');
    }
    else {
      $drupalRoot = \Drupal::root();
      $targetPath = dirname($drupalRoot) . '/private';

      // Create the directory outside web root (best practice).
      if (!is_dir($targetPath)) {
        if (!mkdir($targetPath, 0750, TRUE)) {
          throw new \Exception(dt('Could not create private directory at @path. Check filesystem permissions.', ['@path' => $targetPath]));
        }
        $this->output()->writeln(dt('[done] Created private directory: @path', ['@path' => $targetPath]));
      }
      else {
        $this->output()->writeln(dt('[skip] Private directory already exists: @path', ['@path' => $targetPath]));
      }

      // Append file_private_path to settings.php.
      $settingsFile = $drupalRoot . '/sites/default/settings.php';
      if (!is_writable($settingsFile)) {
        $this->output()->writeln(dt(
          "\n[manual] @file is not writable. Add this line manually:\n\$settings['file_private_path'] = '@path';",
          ['@file' => $settingsFile, '@path' => $targetPath]
        ));
        return;
      }
      $settingsContents = file_get_contents($settingsFile);
      if (str_contains($settingsContents, 'file_private_path')) {
        $this->output()->writeln('[skip] file_private_path already present in settings.php.');
      }
      else {
        file_put_contents($settingsFile, "\$settings['file_private_path'] = '{$targetPath}';\n", FILE_APPEND);
        $this->output()->writeln(dt('[done] Appended file_private_path to @file', ['@file' => $settingsFile]));
      }
    }

    // Create storage directories using native mkdir on the resolved FS path.
    // (The private:// stream wrapper is not registered in this process even
    // after writing settings.php, so we resolve the path directly.)
    $privateBase = \Drupal::config('instruckt_drupal.settings')->get('storage_path');
    // Resolve private:// to an FS path via config or fall back to the computed path.
    $resolvedPrivate = $this->streamWrapperManager->isValidScheme('private')
      ? $this->fileSystem->realpath('private://') ?: (dirname(\Drupal::root()) . '/private')
      : dirname(\Drupal::root()) . '/private';
    $instrucktDir   = $resolvedPrivate . '/_instruckt';
    $screenshotsDir = $instrucktDir . '/screenshots';
    foreach ([$instrucktDir, $screenshotsDir] as $dir) {
      if (!is_dir($dir)) {
        mkdir($dir, 0750, TRUE);
        $this->output()->writeln(dt('[done] Created directory: @dir', ['@dir' => $dir]));
      }
      else {
        $this->output()->writeln(dt('[skip] Directory already exists: @dir', ['@dir' => $dir]));
      }
    }

    // 1. Resolve user.
    $userStorage = $this->entityTypeManager->getStorage('user');
    $user = ctype_digit($options['user'])
      ? $userStorage->load((int) $options['user'])
      : (($users = $userStorage->loadByProperties(['name' => $options['user']])) ? reset($users) : NULL);
    if ($user === NULL) {
      throw new \Exception(dt('Could not find user "@u".', ['@u' => $options['user']]));
    }
    $uid = (string) $user->id();
    $this->output()->writeln(dt('Token user: @name (uid=@uid)', ['@name' => $user->getAccountName(), '@uid' => $uid]));

    // 2. Grant 'use mcp server' to the specified role.
    $role = $this->entityTypeManager->getStorage('user_role')->load($options['role']);
    if ($role === NULL) {
      throw new \Exception(dt('Role "@r" does not exist.', ['@r' => $options['role']]));
    }
    if ($role->hasPermission('use mcp server')) {
      $this->output()->writeln(dt('[skip] Role "@r" already has "use mcp server".', ['@r' => $options['role']]));
    }
    else {
      $role->grantPermission('use mcp server');
      $role->save();
      $this->output()->writeln(dt('[done] Granted "use mcp server" to "@r".', ['@r' => $options['role']]));
    }

    // 3. Create key entity (skip + reuse value if it already exists).
    $keyId = $options['key-id'];
    $existingKey = $this->keyRepository->getKey($keyId);
    if ($existingKey !== NULL) {
      $rawToken = $existingKey->getKeyValue();
      $this->output()->writeln(dt('[skip] Key "@id" already exists; using existing value.', ['@id' => $keyId]));
    }
    else {
      $rawToken = bin2hex(random_bytes(32));
      $key = $this->entityTypeManager->getStorage('key')->create([
        'id'           => $keyId,
        'label'        => 'Instruckt MCP Token',
        'description'  => 'Created by drush instruckt:setup.',
        'key_type'     => 'authentication',
        'key_provider' => 'config',
        'key_input'    => 'text_field',
      ]);
      $key->setKeyValue($rawToken);
      $key->save();
      $this->output()->writeln(dt('[done] Created key "@id".', ['@id' => $keyId]));
    }

    // 4. Configure mcp.settings auth.
    $mcpConfig = $this->configFactory->getEditable('mcp.settings');
    $alreadyConfigured =
      $mcpConfig->get('enable_auth') === TRUE &&
      $mcpConfig->get('auth_settings.enable_token_auth') === TRUE &&
      $mcpConfig->get('auth_settings.token_key') === $keyId &&
      (string) $mcpConfig->get('auth_settings.token_user') === $uid;

    if ($alreadyConfigured) {
      $this->output()->writeln('[skip] mcp.settings auth already configured correctly.');
    }
    else {
      $mcpConfig
        ->set('enable_auth', TRUE)
        ->set('auth_settings.enable_token_auth', TRUE)
        ->set('auth_settings.token_key', $keyId)
        ->set('auth_settings.token_user', $uid)
        ->save();
      $this->output()->writeln('[done] Configured mcp.settings token authentication.');
    }

    // 5. Print the result.
    $base64Token = base64_encode($rawToken);
    $snippet = json_encode([
      'mcpServers' => [
        'instrucktdrupal' => [
          'type'    => 'http',
          'url'     => 'https://your-site.example.com/mcp/post',
          'headers' => ['Authorization' => 'Basic ' . $base64Token],
        ],
      ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $this->output()->writeln("\nSetup complete. Add to .mcp.json (replace the URL):\n");
    $this->output()->writeln($snippet);
    $this->output()->writeln(dt("\nRaw token (store securely): @t", ['@t' => $rawToken]));
  }

}
