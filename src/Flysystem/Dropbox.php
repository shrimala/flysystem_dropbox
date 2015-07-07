<?php

/**
 * @file
 * Contains \Drupal\flysystem_dropbox\Flysystem\Dropbox.
 */

namespace Drupal\flysystem_dropbox\Flysystem;

use Dropbox\Client;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\flysystem\Flysystem\Adapter\MissingAdapter;
use Drupal\flysystem\Plugin\FlysystemPluginInterface;
use Drupal\flysystem\Plugin\FlysystemUrlTrait;
use Drupal\image\Entity\ImageStyle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Url;
use League\Flysystem\Dropbox\DropboxAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drupal plugin for the "Dropbox" Flysystem adapter.
 *
 * @Adapter(id = "dropbox")
 */
class Dropbox implements FlysystemPluginInterface, ContainerFactoryPluginInterface {

  use FlysystemUrlTrait {
    getExternalUrl as getDownloadlUrl;
  }

  /**
   * The Dropbox client.
   *
   * @var \Dropbox\Client
   */
  protected $client;

  /**
   * The Dropbox client ID.
   *
   * @var string
   */
  protected $clientId;

  /**
   * The http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The path prefix inside the Dropbox folder.
   *
   * @var string
   */
  protected $prefix;

  /**
   * The Dropbox API token.
   *
   * @var string
   */
  protected $token;

  /**
   * Whether to serve files via Dropbox.
   *
   * @var bool
   */
  protected $usePublic;

  /**
   * Constructs a Dropbox object.
   *
   * @param array $configuration
   *   Plugin configuration array.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(array $configuration, ClientInterface $http_client) {
    $this->prefix = isset($configuration['prefix']) ? $configuration['prefix'] : '';
    $this->token = $configuration['token'];
    $this->clientId = $configuration['client_id'];
    $this->usePublic = !empty($configuration['public']);
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $container->get('http_client'));
  }

  /**
   * {@inheritdoc}
   */
  public function getAdapter() {
    try {
      $adapter = new DropboxAdapter($this->getClient(), $this->prefix);
    }

    catch (\Exception $e) {
      $adapter = new MissingAdapter();
    }

    return $adapter;
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl($uri) {
    if ($this->usePublic && $url = $this->getPublicUrl($uri)) {
      return $url;
    }

    return $this->getDownloadlUrl($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function ensure($force = FALSE) {
    try {
      $info = $this->getClient()->getAccountInfo();
    }
    catch (\Exception $e) {
      return [[
        'severity' => RfcLogLevel::ERROR,
        'message' => 'The Dropbox client failed with: %error.',
        'context' => ['%error' => $e->getMessage()],
      ]];
    }

    return [];
  }

  /**
   * Returns the public Dropbox URL.
   *
   * @param string $uri
   *   The file URI.
   *
   * @return string|false
   *   The public URL, or false on failure.
   */
  protected function getPublicUrl($uri) {
    $target = $this->getTarget($uri);

    // Quick exit for existing files.
    if ($link = $this->getSharableLink($target)) {
      return $link;
    }

    // Support image style generation.
    if (strpos($target, 'styles/') === 0 && $this->generateImageStyle($uri)) {
      return $this->getSharableLink($target);
    }

    return FALSE;
  }

  /**
   * Generates an image style for a URI.
   *
   * @todo This should probably be moved to flysystem proper.
   *
   * @param string $uri
   *   The image style URI.
   *
   * @return bool
   *   True on success, false on failure.
   */
  protected function generateImageStyle($uri) {
    $target = $this->getTarget($uri);
    if (substr_count($target, '/') < 3) {
      return FALSE;
    }

    list(, $style, $scheme, $file) = explode('/', $target, 4);

    if (!$image_style = ImageStyle::load($style)) {
      return FALSE;
    }

    $url = Url::fromString($this->getDownloadlUrl($uri));
    $url->getQuery()->set('itok', $image_style->getPathToken($scheme . '://' . $file));

    try {
      $response = $this->httpClient->get((string) $url);
      return $response->getStatusCode() == 200;
    }
    catch (RequestException $e) {}

    return FALSE;
  }

  /**
   * Returns the Dropbox sharable link.
   *
   * @param string $target
   *   The file target.
   *
   * @return string|bool
   *   The sharable link, or false on failure.
   */
  protected function getSharableLink($target) {
    try {
      $link = $this->getClient()->createShareableLink('/' . $target);
    }
    catch (\Exception $e) {}

    if (empty($link)) {
      return FALSE;
    }

    $url = Url::fromString($link);
    $url->getQuery()->set('dl', 1);

    return (string) $url;
  }

  /**
   * Returns the Dropbox client.
   *
   * @return \Dropbox\Client
   *   The Dropbox client.
   */
  protected function getClient() {
    if (!isset($this->client)) {
      $this->client = new Client($this->token, $this->clientId);
    }

    return $this->client;
  }

}
