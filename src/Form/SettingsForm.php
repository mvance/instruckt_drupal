<?php

declare(strict_types=1);

namespace Drupal\instruckt_drupal\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an administration settings form for the instruckt_drupal module.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'instruckt_drupal_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['instruckt_drupal.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('instruckt_drupal.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Instruckt toolbar'),
      '#description' => $this->t('Master on/off switch. When disabled, the toolbar is hidden for all users.'),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    $form['storage_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage path'),
      '#description' => $this->t('Stream wrapper URI where annotations and screenshots are stored (e.g. <code>private://_instruckt</code>). Must use the <code>private://</code> scheme to prevent web-accessible exposure of screenshot files. <strong>Warning:</strong> changing this after installation leaves existing data at the old path; you must move the directory manually.'),
      '#default_value' => $config->get('storage_path'),
    ];

    $max_mb = round((int) $config->get('max_screenshot_size') / 1048576, 1);
    $form['max_screenshot_size_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum screenshot size (MB)'),
      '#description' => $this->t('Maximum allowed size for uploaded screenshots in megabytes. Decimals accepted (e.g. 2.5). Stored internally as bytes.'),
      '#default_value' => $max_mb,
      '#step' => 0.1,
    ];

    $form['allowed_screenshot_extensions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed screenshot formats'),
      '#description' => $this->t('At least one format must be selected.'),
      '#options' => ['png' => $this->t('PNG'), 'svg' => $this->t('SVG')],
      '#default_value' => $config->get('allowed_screenshot_extensions') ?? ['png', 'svg'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $path = trim((string) $form_state->getValue('storage_path'));
    if ($path === '') {
      $form_state->setErrorByName('storage_path', $this->t('Storage path must not be empty.'));
    }
    elseif (!str_starts_with($path, 'private://')) {
      $form_state->setErrorByName('storage_path', $this->t('Storage path must use the <code>private://</code> stream wrapper.'));
    }

    $mb = (float) $form_state->getValue('max_screenshot_size_mb');
    if ($mb <= 0) {
      $form_state->setErrorByName('max_screenshot_size_mb', $this->t('Maximum screenshot size must be a positive number.'));
    }
    elseif ($mb > 100) {
      $form_state->setErrorByName('max_screenshot_size_mb', $this->t('Maximum screenshot size cannot exceed 100 MB.'));
    }

    $extensions = array_filter((array) $form_state->getValue('allowed_screenshot_extensions'));
    if (empty($extensions)) {
      $form_state->setErrorByName('allowed_screenshot_extensions', $this->t('At least one screenshot format must be selected.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $bytes = (int) round((float) $form_state->getValue('max_screenshot_size_mb') * 1048576);
    $extensions = array_values(array_filter(
      (array) $form_state->getValue('allowed_screenshot_extensions')
    ));

    $this->config('instruckt_drupal.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('storage_path', trim((string) $form_state->getValue('storage_path')))
      ->set('max_screenshot_size', $bytes)
      ->set('allowed_screenshot_extensions', $extensions)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
