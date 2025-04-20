<?php
namespace MagoArab\CdnIntegration\Model;

use MagoArab\CdnIntegration\Helper\Data as Helper;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;

class AdvancedUrlAnalyzer
{
    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var Curl
     */
    protected $curl;
    
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    
    /**
     * @var array
     */
    protected $visitedUrls = [];
    
    /**
     * @var array
     */
    protected $discoveredAssets = [];
    
    /**
     * @var int
     */
    protected $maxPagesToVisit = 5;
    
    /**
     * @var int
     */
    protected $visitedCount = 0;
    
    /**
     * @var string
     */
    protected $baseUrl;
    
    /**
     * @param Helper $helper
     * @param Curl $curl
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Helper $helper,
        Curl $curl,
        StoreManagerInterface $storeManager
    ) {
        $this->helper = $helper;
        $this->curl = $curl;
        $this->storeManager = $storeManager;
    }
    
    /**
     * Analyze store for static and media URLs
     *
     * @param string|null $startUrl
     * @param int $maxPages
     * @return array
     */
    public function analyze($startUrl = null, $maxPages = 5)
    {
        // Reset state
        $this->visitedUrls = [];
        $this->discoveredAssets = [];
        $this->visitedCount = 0;
        $this->maxPagesToVisit = $maxPages;
        
        if (!$startUrl) {
            $this->baseUrl = $this->storeManager->getStore()->getBaseUrl();
        } else {
            $this->baseUrl = $startUrl;
            
            // Ensure baseUrl ends with a slash
            if (substr($this->baseUrl, -1) !== '/') {
                $this->baseUrl .= '/';
            }
        }
        
        $this->helper->log("Starting advanced URL analysis from: {$this->baseUrl}", 'info');
        
        // Start with the homepage
        $this->crawlPage($this->baseUrl);
        
        $this->helper->log("Analysis complete. Visited {$this->visitedCount} pages, found " . 
            count($this->discoveredAssets) . " unique static/media assets.", 'info');
        
        // Return unique, sorted list of discovered assets
        return $this->getDiscoveredAssets();
    }
    
    /**
     * Check if URL looks like a category page
     * 
     * @param string $url
     * @return bool
     */
    protected function isCategoryPage($url)
    {
        // Category page patterns in Magento
        $categoryPatterns = [
            '/\/catalog\/category\/view/',        // Standard category link
            '/\.html$/',                          // Rewritten URLs ending with .html
            '/\/category\//',                     // Category URL identifier (common in URL customizations)
            '/\?cat=/',                           // Old style category links with cat parameter
        ];
        
        foreach ($categoryPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                // Ensure it's not a static asset
                if (!preg_match('/\.(js|css|jpg|jpeg|png|gif|svg|woff|woff2|ttf|eot)$/', $url)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if URL looks like a product page
     * 
     * @param string $url
     * @return bool
     */
    protected function isProductPage($url)
    {
        // Product page patterns in Magento
        $productPatterns = [
            '/\/catalog\/product\/view/',        // Standard product link
            '/\.html$/',                         // Rewritten URLs ending with .html
            '/\/product\//',                     // Product URL identifier (common in URL customizations)
        ];
        
        foreach ($productPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                // Ensure it's not a static asset
                if (!preg_match('/\.(js|css|jpg|jpeg|png|gif|svg|woff|woff2|ttf|eot)$/', $url)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Crawl a page and extract assets and links
     *
     * @param string $url
     * @return void
     */
    protected function crawlPage($url)
    {
        // Skip if we've already visited this URL or reached the limit
        if (in_array($url, $this->visitedUrls) || $this->visitedCount >= $this->maxPagesToVisit) {
            return;
        }
        
        $this->helper->log("Crawling page: {$url}", 'debug');
        $this->visitedUrls[] = $url;
        $this->visitedCount++;
        
        // For category pages, allocate extra crawl budget
        $isCategoryPage = $this->isCategoryPage($url);
        if ($isCategoryPage) {
            $this->helper->log("Found category page: {$url}, increasing depth", 'debug');
            $tempMaxPages = $this->maxPagesToVisit;
            $this->maxPagesToVisit += 2; // Add extra pages for category pages
        }
        
        // Fetch the page content
        $content = $this->fetchUrl($url);
        if (empty($content)) {
            $this->helper->log("Failed to fetch content from: {$url}", 'warning');
            
            // Restore original maxPagesToVisit if this was a category page
            if ($isCategoryPage) {
                $this->maxPagesToVisit = $tempMaxPages;
            }
            
            return;
        }
        
        // Extract and store static/media assets
        $assets = $this->extractAssets($content);
        foreach ($assets as $asset) {
            if (!in_array($asset, $this->discoveredAssets)) {
                $this->discoveredAssets[] = $asset;
            }
        }
        
        // Extract links to other pages on the same domain
        $links = $this->extractLinks($content, $url);
        
        // Restore original maxPagesToVisit if this was a category page
        if ($isCategoryPage) {
            $this->maxPagesToVisit = $tempMaxPages;
        }
        
        // Prioritize category pages and product pages for crawling
        $categoryLinks = [];
        $productLinks = [];
        $otherLinks = [];
        
        foreach ($links as $link) {
            if ($this->isCategoryPage($link)) {
                $categoryLinks[] = $link;
            } elseif ($this->isProductPage($link)) {
                $productLinks[] = $link;
            } else {
                $otherLinks[] = $link;
            }
        }
        
        // Combine arrays with priorities
        $prioritizedLinks = array_merge($categoryLinks, $productLinks, $otherLinks);
        
        // Visit each link (depth-first) with the prioritized order
        foreach ($prioritizedLinks as $link) {
            if ($this->visitedCount < $this->maxPagesToVisit) {
                $this->crawlPage($link);
            } else {
                break;
            }
        }
    }
    
    /**
     * Fetch URL content using cURL
     *
     * @param string $url
     * @return string
     */
    protected function fetchUrl($url)
    {
        try {
            // Create new curl instance for each request to avoid conflicts
            $this->curl = new Curl();
            
            // Set browser-like user agent
            $this->curl->setOption(CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $this->curl->setOption(CURLOPT_MAXREDIRS, 5);
            $this->curl->setOption(CURLOPT_TIMEOUT, 30);
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->setOption(CURLOPT_SSL_VERIFYHOST, false);
            
            // Add accept headers to mimic browser
            $this->curl->addHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8');
            $this->curl->addHeader('Accept-Language', 'en-US,en;q=0.5');
            
            $this->curl->get($url);
            $statusCode = $this->curl->getStatus();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                return $this->curl->getBody();
            }
            
            $this->helper->log("Error fetching URL {$url}: HTTP status {$statusCode}", 'warning');
            return '';
        } catch (\Exception $e) {
            $this->helper->log("Exception fetching URL {$url}: " . $e->getMessage(), 'error');
            return '';
        }
    }
    
    /**
     * Extract static and media assets from HTML content
     *
     * @param string $content
     * @return array
     */
    protected function extractAssets($content)
    {
        $assets = [];
        
        // Comprehensive patterns to find all types of static and media assets
        $patterns = [
            // CSS links
            '/<link[^>]*href=[\'"]([^\'"]+\.css(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // JavaScript files
            '/<script[^>]*src=[\'"]([^\'"]+\.js(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Images
            '/<img[^>]*src=[\'"]([^\'"]+\.(png|jpg|jpeg|gif|svg|webp)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Product images in various Magento themes
            '/<img[^>]*class=[\'"]*(?:product-image|category-image|gallery-placeholder)[^\'"]* src=[\'"]((?:[^\'"]+\.(png|jpg|jpeg|gif|svg|webp))(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Product images in list
            '/<img[^>]*class=[\'"]*product-image-photo[^\'"]* src=[\'"]((?:[^\'"]+\.(png|jpg|jpeg|gif|svg|webp))(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Images with ID for products
            '/<img[^>]*id=[\'"]*product-collection-image[^\'"]* src=[\'"]((?:[^\'"]+\.(png|jpg|jpeg|gif|svg|webp))(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Lazy-loaded product images
            '/<img[^>]*data-src=[\'"]((?:[^\'"]+\.(png|jpg|jpeg|gif|svg|webp))(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Background images in inline styles
            '/style=[\'"][^"\']*background(?:-image)?:\s*url\([\'"]?([^\'")+\s]+)[\'"]?\)[^"\']*[\'"]/',
            
            // Fonts and other assets in CSS url()
            '/url\([\'"]?([^\'")\s]+)[\'"]?\)/i',
            
            // Video and audio sources
            '/<(?:video|audio)[^>]*>.*?<source[^>]*src=[\'"]([^\'"]+)[\'"].*?<\/(?:video|audio)>/is',
            
            // Media in object/embed tags
            '/<(?:object|embed)[^>]*(?:data|src)=[\'"]([^\'"]+)[\'"][^>]*>/i',
            
            // Data attributes with URLs
            '/ data-[^=]*=[\'"]([^\'"]+\.(js|css|png|jpg|jpeg|gif)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // SVG images in various positions
            '/<[^>]*?(?:href|src)=[\'"]([^\'"]+\.svg(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Preload links
            '/<link[^>]*rel=[\'"]preload[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i',
            
            // Import statements in styles
            '/@import\s+[\'"]([^\'"]+)[\'"]/i'
        ];
        
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            
            if (isset($matches[1]) && !empty($matches[1])) {
                foreach ($matches[1] as $asset) {
                    $asset = $this->normalizeUrl($asset);
                    
                    // Keep only static and media URLs
                    if ($this->isValidAssetUrl($asset)) {
                        $assets[] = $asset;
                    }
                }
            }
        }
        
        // Special patterns for Magento-specific resources
        $magentoPatterns = [
            // Merged JS/CSS files - Enhanced version for better coverage
            '/\/static\/_cache\/merged\/[^"\')\s<>]+/i',
            
            // Minified JS/CSS files
            '/\/static\/_cache\/minified\/[^"\')\s<>]+/i',
            
            // RequireJS text plugin
            '/text!(\/static\/[^!]+)/i',
            
            // RequireJS define paths
            '/"(\/static\/[^"]+)"/i',
            
            // Additional RequireJS resource pattern
            '/["\']((?:\/static|\/media)\/[^"\']+\.(?:js|css|svg|png|jpg|jpeg|gif|woff|woff2|ttf|eot)(?:\?[^\'"]*)?)["\']/'
        ];
        
        foreach ($magentoPatterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            
            $matchesArray = !empty($matches[1]) ? $matches[1] : $matches[0];
            
            if (!empty($matchesArray)) {
                foreach ($matchesArray as $asset) {
                    $asset = $this->normalizeUrl($asset);
                    if ($this->isValidAssetUrl($asset)) {
                        $assets[] = $asset;
                    }
                }
            }
        }
        
        // Look for JSON data that might contain URLs
        if (preg_match_all('/\{[^}]+\}/m', $content, $jsonMatches)) {
            foreach ($jsonMatches[0] as $jsonString) {
                // Look for image-related keys in JSON
                if (strpos($jsonString, 'image') !== false || strpos($jsonString, 'img') !== false || strpos($jsonString, 'photo') !== false) {
                    // Search for image URLs in JSON strings
                    if (preg_match_all('/"(?:thumbnail|small_image|image|img_url|src|full)":\s*"([^"]+\.(jpg|jpeg|png|gif))"/', $jsonString, $imgMatches)) {
                        foreach ($imgMatches[1] as $imgUrl) {
                            $imgUrl = $this->normalizeUrl($imgUrl);
                            if ($this->isValidAssetUrl($imgUrl)) {
                                $assets[] = $imgUrl;
                            }
                        }
                    }
                }
                
                // General pattern for any static/media URLs in JSON
                if (preg_match_all('/"(\/(?:static|media)\/[^"]+)"/i', $jsonString, $jsonPaths)) {
                    foreach ($jsonPaths[1] as $asset) {
                        $asset = $this->normalizeUrl($asset);
                        if ($this->isValidAssetUrl($asset)) {
                            $assets[] = $asset;
                        }
                    }
                }
            }
        }
        
        // Remove duplicates and sort
        $assets = array_unique($assets);
        sort($assets);
        
        return $assets;
    }
    
    /**
     * Normalize URL
     *
     * @param string $url
     * @return string
     */
    private function normalizeUrl($url)
    {
        // Skip data URLs
        if (strpos($url, 'data:') === 0) {
            return '';
        }
        
        // Remove query parameters
        $url = strtok($url, '?');
        
        // Convert absolute URLs to relative paths
        if (strpos($url, $this->baseUrl) === 0) {
            $url = substr($url, strlen($this->baseUrl) - 1); // -1 to keep the leading slash
        } elseif (strpos($url, 'http') === 0) {
            // For other domains, skip
            $parsedUrl = parse_url($url);
            $currentDomain = parse_url($this->baseUrl, PHP_URL_HOST);
            
            if (isset($parsedUrl['host']) && $parsedUrl['host'] !== $currentDomain) {
                return '';
            }
            
            // Extract the path from absolute URL
            $url = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        }
        
        // Ensure it starts with a slash
        if (!empty($url) && strpos($url, '/') !== 0) {
            $url = '/' . $url;
        }
        
        return $url;
    }
    
    /**
     * Check if URL is a valid asset URL
     * 
     * @param string $url
     * @return bool
     */
    private function isValidAssetUrl($url)
    {
        // Ensure URL is valid
        if (empty($url)) {
            return false;
        }
        
        // Keep only static and media URLs
        return (strpos($url, '/static/') === 0 || strpos($url, '/media/') === 0);
    }
    
    /**
     * Extract links to other pages on the same domain
     *
     * @param string $content
     * @param string $baseUrl
     * @return array
     */
    protected function extractLinks($content, $baseUrl)
    {
        $links = [];
        $baseUrlDomain = parse_url($this->baseUrl, PHP_URL_HOST);
        
        // Find all links
        preg_match_all('/<a[^>]*href=[\'"]([^\'"#]+)[\'"][^>]*>/i', $content, $matches);
        
        if (isset($matches[1]) && !empty($matches[1])) {
            foreach ($matches[1] as $link) {
                // Skip JavaScript links, mailto, tel, etc.
                if (strpos($link, 'javascript:') === 0 || 
                    strpos($link, 'mailto:') === 0 || 
                    strpos($link, 'tel:') === 0) {
                    continue;
                }
                
                // Handle relative URLs
                if (strpos($link, 'http') !== 0) {
                    // Handle different relative path formats
                    if (strpos($link, '/') === 0) {
                        // Absolute path relative to domain
                        $domain = parse_url($this->baseUrl, PHP_URL_SCHEME) . '://' . $baseUrlDomain;
                        $link = $domain . $link;
                    } else {
                        // Relative to current path
                        $basePath = dirname($baseUrl);
                        $link = $basePath . '/' . $link;
                    }
                }
                
                // Only include links to the same domain
                $linkDomain = parse_url($link, PHP_URL_HOST);
                if ($linkDomain === $baseUrlDomain) {
                    // Make sure we don't include static/media URLs
                    $path = parse_url($link, PHP_URL_PATH);
                    if (strpos($path, '/static/') !== 0 && strpos($path, '/media/') !== 0) {
                        $links[] = $link;
                    }
                }
            }
        }
        
        return $links;
    }
    
    /**
     * Get discovered assets
     *
     * @return array
     */
    public function getDiscoveredAssets()
    {
        return $this->discoveredAssets;
    }
}