<?php
namespace MagoArab\CdnIntegration\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;

class ReplaceStaticUrls implements ObserverInterface
{
    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    
    /**
     * @var State
     */
    protected $appState;
    
    /**
     * @var array Cache for replaced URLs
     */
    protected $replacedUrlsCache = [];
    
    /**
     * @var array Cache for skipped URLs
     */
    protected $skippedUrlsCache = [];
    
    /**
     * @param Helper $helper
     * @param ScopeConfigInterface $scopeConfig
     * @param State $appState
     */
    public function __construct(
        Helper $helper,
        ScopeConfigInterface $scopeConfig,
        State $appState
    ) {
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
        $this->appState = $appState;
    }
    
    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }
        
        // Check if custom URLs are defined
        $customUrls = $this->helper->getCustomUrls();
        if (empty($customUrls)) {
            $this->helper->log("No custom URLs defined. Skipping replacement.", 'debug');
            return;
        }
        
        // Skip admin area
        try {
            $areaCode = $this->appState->getAreaCode();
            if ($areaCode === Area::AREA_ADMINHTML) {
                $this->helper->log("Skipping admin area", 'debug');
                return;
            }
        } catch (\Exception $e) {
            // Check URL for admin path as a fallback
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, '/admin/') !== false) {
                $this->helper->log("Skipping admin path: {$requestUri}", 'debug');
                return;
            }
        }
        
        $response = $observer->getEvent()->getResponse();
        if (!$response) {
            return;
        }
        
        $html = $response->getBody();
        if (empty($html)) {
            return;
        }
        
        // Get the CDN base URL
        $cdnBaseUrl = $this->helper->getCdnBaseUrl();
        if (empty($cdnBaseUrl)) {
            $this->helper->log("CDN base URL is empty", 'warning');
            return;
        }
        
        // Get the base URLs
        $baseUrl = rtrim($this->scopeConfig->getValue(
            \Magento\Store\Model\Store::XML_PATH_UNSECURE_BASE_URL,
            ScopeInterface::SCOPE_STORE
        ), '/');
        
        $secureBaseUrl = rtrim($this->scopeConfig->getValue(
            \Magento\Store\Model\Store::XML_PATH_SECURE_BASE_URL,
            ScopeInterface::SCOPE_STORE
        ), '/');
        
        // Safe file types to use with CDN
        $safeFileTypes = ['css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'js', 'woff', 'woff2', 'ttf', 'eot'];
        
        // Files to always exclude from CDN
        $criticalFiles = [
            'requirejs/require.js',
            'requirejs-config.js', 
            'mage/requirejs/mixins.js',
            'mage/polyfill.js',
            'mage/bootstrap.js',
            'jquery.js',
            'jquery.min.js',
            'jquery-migrate.js',
            'jquery-migrate.min.js',
            'jquery-ui.js',
            'jquery-ui.min.js',
            'require.js',
            'underscore.js',
            'knockout.js'
        ];

        // Initialize replacement counter and arrays for debugging
        $replacementCount = 0;
        $replacedUrls = [];
        $failedUrls = [];
        
        // 1. First, use a more aggressive replacement for CSS and JS files
        // This is especially important for .min.js and .min.css files
        if (preg_match_all('/(href|src)=[\'"](\/[^"\']+\.(js|css)(\?[^\'"]*)?)[\'"]/', $html, $matches)) {
            foreach ($matches[2] as $urlIndex => $url) {
                $url = $matches[2][$urlIndex];
                
                // Skip URLs that are not static or media
                if (strpos($url, '/static/') !== 0 && strpos($url, '/media/') !== 0) {
                    continue;
                }
                
                // Skip URLs already in cache
                $cacheKey = md5($url);
                if (isset($this->replacedUrlsCache[$cacheKey]) || isset($this->skippedUrlsCache[$cacheKey])) {
                    continue;
                }
                
                // Skip critical files
                $shouldSkip = false;
                foreach ($criticalFiles as $criticalFile) {
                    if (strpos($url, $criticalFile) !== false) {
                        $shouldSkip = true;
                        break;
                    }
                }
                
                if ($shouldSkip) {
                    $this->skippedUrlsCache[$cacheKey] = true;
                    continue;
                }
                
                // Determine path to use in CDN URL
                $cdnPath = '';
                if (strpos($url, '/static/') === 0) {
                    $cdnPath = substr($url, 8); // Remove '/static/'
                } elseif (strpos($url, '/media/') === 0) {
                    $cdnPath = substr($url, 7); // Remove '/media/'
                }
                
                if (empty($cdnPath)) {
                    $this->skippedUrlsCache[$cacheKey] = true;
                    continue;
                }
                
                // Create full CDN URL - make sure there's no double slash
                $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                
                // Extract full tag to replace
                $fullTag = $matches[0][$urlIndex];
                $newTag = str_replace($url, $cdnUrl, $fullTag);
                
                // Replace just this exact instance
                $pos = strpos($html, $fullTag);
                if ($pos !== false) {
                    $html = substr_replace($html, $newTag, $pos, strlen($fullTag));
                    $replacementCount++;
                    $replacedUrls[$url] = $cdnUrl;
                    $this->replacedUrlsCache[$cacheKey] = true;
                } else {
                    $failedUrls[] = $url;
                }
            }
        }
        
        // 2. Process each custom URL in the exact order they were defined
        // to maintain CSS and JavaScript loading order
        foreach ($customUrls as $url) {
            $cacheKey = md5($url);
            
            // Skip if already processed
            if (isset($this->replacedUrlsCache[$cacheKey])) {
                continue;
            }
            
            $html = $this->processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
        }
        
        // 3. Handle specific problematic patterns that might be missed
        // This particularly helps with dynamically inserted scripts and inline styles
        if (preg_match_all('/[\'"]([\/][^"\']+\.(js|css)(\?[^\'"]*)?)[\'"]/', $html, $quotedMatches)) {
            foreach ($quotedMatches[1] as $url) {
                // Skip already processed URLs
                $cacheKey = md5($url);
                if (isset($this->replacedUrlsCache[$cacheKey]) || isset($this->skippedUrlsCache[$cacheKey])) {
                    continue;
                }
                
                // Process this URL
                $html = $this->processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
            }
        }
        
        // Log stats
        if ($replacementCount > 0) {
            $this->helper->log("Replaced {$replacementCount} URLs with CDN URLs", 'info');
            
            // Detailed debug log if debug mode is enabled
            if ($this->helper->isDebugEnabled()) {
                $this->helper->log("Replaced URLs: " . json_encode($replacedUrls), 'debug');
                if (!empty($failedUrls)) {
                    $this->helper->log("Failed to replace URLs: " . json_encode($failedUrls), 'debug');
                }
            }
        }
        
        $response->setBody($html);
    }
    
    /**
     * Replace a specific URL in HTML content
     * This method ensures exact replacements to maintain file order
     *
     * @param string $html
     * @param string $url
     * @param string $cdnBaseUrl
     * @param string $baseUrl
     * @param string $secureBaseUrl
     * @param array $safeFileTypes
     * @param array $criticalFiles
     * @param int &$replacementCount
     * @param array &$replacedUrls
     * @return string
     */
    private function processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, &$replacementCount, &$replacedUrls)
    {
        try {
            // Skip if URL is empty
            if (empty($url)) {
                return $html;
            }
            
            // Normalize URL (remove domain if present)
            $normalizedUrl = $url;
            if (strpos($url, 'http') === 0) {
                $parsedUrl = parse_url($url);
                if (isset($parsedUrl['path'])) {
                    $normalizedUrl = $parsedUrl['path'];
                }
            }
            
            // Ensure URL starts with a slash
            if (strpos($normalizedUrl, '/') !== 0) {
                $normalizedUrl = '/' . $normalizedUrl;
            }
            
            // Skip if not a static or media URL
            if (strpos($normalizedUrl, '/static/') !== 0 && strpos($normalizedUrl, '/media/') !== 0) {
                return $html;
            }
            
            // Skip URLs already in cache
            $cacheKey = md5($normalizedUrl);
            if (isset($this->replacedUrlsCache[$cacheKey]) || isset($this->skippedUrlsCache[$cacheKey])) {
                return $html;
            }
            
            // Special support for merged files
            $isMergedFile = false;
            if (strpos($normalizedUrl, '/_cache/merged/') !== false || 
                strpos($normalizedUrl, '/_cache/minified/') !== false) {
                $isMergedFile = true;
            }
            
            // Check if it's a safe file type
            $ext = strtolower(pathinfo($normalizedUrl, PATHINFO_EXTENSION));
            if (!in_array($ext, $safeFileTypes) && !$isMergedFile) {
                $this->skippedUrlsCache[$cacheKey] = true;
                return $html;
            }
            
            // Skip critical files
            foreach ($criticalFiles as $criticalFile) {
                if (strpos($normalizedUrl, $criticalFile) !== false) {
                    $this->skippedUrlsCache[$cacheKey] = true;
                    return $html;
                }
            }
            
            // Determine path to use in CDN URL
            $cdnPath = '';
            if (strpos($normalizedUrl, '/static/') === 0) {
                $cdnPath = substr($normalizedUrl, 8); // Remove '/static/'
            } elseif (strpos($normalizedUrl, '/media/') === 0) {
                $cdnPath = substr($normalizedUrl, 7); // Remove '/media/'
            }
            
            if (empty($cdnPath)) {
                $this->skippedUrlsCache[$cacheKey] = true;
                return $html;
            }
            
            // Create full CDN URL - make sure there's no double slash
            $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
            
            // Store original HTML for comparison
            $originalHtml = $html;
            
            // Process absolute URLs with domain - full URL replacements
            if (!empty($baseUrl)) {
                $absoluteUrl = $baseUrl . $normalizedUrl;
                if (strpos($html, $absoluteUrl) !== false) {
                    $html = str_replace($absoluteUrl, $cdnUrl, $html);
                }
            }
            
            if (!empty($secureBaseUrl)) {
                $secureAbsoluteUrl = $secureBaseUrl . $normalizedUrl;
                if (strpos($html, $secureAbsoluteUrl) !== false) {
                    $html = str_replace($secureAbsoluteUrl, $cdnUrl, $html);
                }
            }
            
            // Four precise replacement patterns for different contexts
            
            // 1. href attribute
            $pattern = '/(\shref=["\'])(' . preg_quote($normalizedUrl, '/') . ')(["\'])/';
            $html = preg_replace_callback(
                $pattern,
                function($matches) use ($cdnUrl, &$replacementCount) {
                    $replacementCount++;
                    return $matches[1] . $cdnUrl . $matches[3];
                },
                $html
            );
            
            // 2. src attribute
            $pattern = '/(\ssrc=["\'])(' . preg_quote($normalizedUrl, '/') . ')(["\'])/';
            $html = preg_replace_callback(
                $pattern,
                function($matches) use ($cdnUrl, &$replacementCount) {
                    $replacementCount++;
                    return $matches[1] . $cdnUrl . $matches[3];
                },
                $html
            );
            
            // 3. url() in CSS - try various formats
            $patterns = [
                '/url\([\'"]?' . preg_quote($normalizedUrl, '/') . '[\'"]?\)/',
                '/url\([\'"]' . preg_quote($normalizedUrl, '/') . '[\'"]?\)/',
                '/url\(' . preg_quote($normalizedUrl, '/') . '\)/'
            ];
            
            foreach ($patterns as $pattern) {
                $html = preg_replace_callback(
                    $pattern,
                    function($matches) use ($cdnUrl, &$replacementCount) {
                        $replacementCount++;
                        return 'url(' . $cdnUrl . ')';
                    },
                    $html
                );
            }
            
            // 4. Quoted URLs in JavaScript
            $html = preg_replace_callback(
                '/(["\'])(' . preg_quote($normalizedUrl, '/') . ')(["\'])/',
                function($matches) use ($cdnUrl, &$replacementCount, $normalizedUrl) {
                    // Only replace if it's not inside an HTML tag - this is to avoid duplicate replacements
                    $leftChar = substr($matches[0], 0, 1);
                    $rightChar = substr($matches[0], -1);
                    
                    // Only replace if matched quotes are the same
                    if ($leftChar === $rightChar) {
                        $replacementCount++;
                        return $leftChar . $cdnUrl . $rightChar;
                    }
                    
                    return $matches[0];
                },
                $html
            );
            
            // If we changed anything, log it and update cache
            if ($html !== $originalHtml) {
                $this->helper->log("Replaced URL: {$normalizedUrl} with {$cdnUrl}", 'debug');
                $replacedUrls[$normalizedUrl] = $cdnUrl;
                $this->replacedUrlsCache[$cacheKey] = true;
            }
            
            return $html;
        } catch (\Exception $e) {
            $this->helper->log("Error processing URL {$url}: " . $e->getMessage(), 'error');
            return $html;
        }
    }
}