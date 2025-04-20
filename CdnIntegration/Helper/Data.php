<?php
namespace MagoArab\CdnIntegration\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Data extends AbstractHelper
{
    const XML_PATH_ENABLED = 'magoarab_cdn/general/enabled';
    const XML_PATH_DEBUG_MODE = 'magoarab_cdn/general/debug_mode';
    const XML_PATH_GITHUB_USERNAME = 'magoarab_cdn/github_settings/username';
    const XML_PATH_GITHUB_REPOSITORY = 'magoarab_cdn/github_settings/repository';
    const XML_PATH_GITHUB_BRANCH = 'magoarab_cdn/github_settings/branch';
    const XML_PATH_GITHUB_TOKEN = 'magoarab_cdn/github_settings/token';
    const XML_PATH_FILE_TYPES = 'magoarab_cdn/cdn_settings/file_types';
    const XML_PATH_EXCLUDED_PATHS = 'magoarab_cdn/cdn_settings/excluded_paths';
    
    /**
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * URL cache for better performance 
     */
    protected $urlCache = [];

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        parent::__construct($context);
    }

    /**
     * Check if the module is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebugEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_DEBUG_MODE,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get GitHub username
     *
     * @return string
     */
    public function getGithubUsername()
    {
        return trim($this->scopeConfig->getValue(
            self::XML_PATH_GITHUB_USERNAME,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * Get GitHub repository name
     *
     * @return string
     */
    public function getGithubRepository()
    {
        return trim($this->scopeConfig->getValue(
            self::XML_PATH_GITHUB_REPOSITORY,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * Get GitHub branch name
     *
     * @return string
     */
    public function getGithubBranch()
    {
        $branch = trim($this->scopeConfig->getValue(
            self::XML_PATH_GITHUB_BRANCH,
            ScopeInterface::SCOPE_STORE
        ));
        
        return $branch ?: 'main';
    }

    /**
     * Get GitHub token
     *
     * @return string
     */
    public function getGithubToken()
    {
        return trim($this->scopeConfig->getValue(
            self::XML_PATH_GITHUB_TOKEN,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * Get file types to be served via CDN
     *
     * @return array
     */
    public function getFileTypes()
    {
        $types = $this->scopeConfig->getValue(
            self::XML_PATH_FILE_TYPES,
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($types)) {
            return ['css', 'js'];
        }
        
        if (is_string($types)) {
            return explode(',', $types);
        }
        
        return $types;
    }

    /**
     * Get excluded paths
     *
     * @return array
     */
    public function getExcludedPaths()
    {
        $paths = $this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_PATHS,
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($paths)) {
            return [];
        }
        
        return array_map('trim', explode("\n", $paths));
    }

    /**
     * Get CDN base URL
     *
     * @return string
     */
    public function getCdnBaseUrl()
    {
        $username = $this->getGithubUsername();
        $repository = $this->getGithubRepository();
        $branch = $this->getGithubBranch();
        
        if (empty($username) || empty($repository) || empty($branch)) {
            return '';
        }
        
        return sprintf(
            'https://cdn.jsdelivr.net/gh/%s/%s@%s/',
            $username,
            $repository,
            $branch
        );
    }

    /**
     * Get custom URLs to serve via CDN
     * This implementation preserves the exact order of URLs as defined in the config
     *
     * @return array
     */
    public function getCustomUrls()
    {
        $urlString = $this->scopeConfig->getValue(
            'magoarab_cdn/custom_urls/custom_url_list',
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($urlString)) {
            return [];
        }
        
        // Split by newlines and preserve exact order
        $urls = preg_split('/\r\n|\r|\n/', $urlString);
        if (!is_array($urls)) {
            return [];
        }
        
        // Clean up the array while preserving order
        $result = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if (!empty($url)) {
                $result[] = $url;
            }
        }
        
        return $result;
    }
    
    /**
     * Log messages if debug mode is enabled
     *
     * @param string $message
     * @param string $level
     * @return void
     */
    public function log($message, $level = 'info')
    {
        if ($this->isDebugEnabled() || $level === 'error') {
            switch ($level) {
                case 'error':
                    $this->logger->error($message);
                    break;
                case 'warning':
                    $this->logger->warning($message);
                    break;
                case 'debug':
                    $this->logger->debug($message);
                    break;
                case 'info':
                default:
                    $this->logger->info($message);
                    break;
            }
        }
    }
}