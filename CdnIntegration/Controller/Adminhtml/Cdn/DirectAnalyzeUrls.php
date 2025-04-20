<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;

class DirectAnalyzeUrls extends Action
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'MagoArab_CdnIntegration::config';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
    }

    /**
     * Direct analyze URLs paste by user
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        try {
            if (!$this->helper->isEnabled()) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('CDN Integration is disabled.')
                ]);
            }
            
            // Get pasted URLs
            $pastedUrls = $this->getRequest()->getParam('pasted_urls');
            $urlList = [];
            
            if (!empty($pastedUrls)) {
                // Split by newlines and clean
                $lines = preg_split('/\r\n|\r|\n/', $pastedUrls);
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $urlList[] = $line;
                    }
                }
            }
            
            // Process the URLs - normalize and filter
            $processedUrls = $this->processUrls($urlList);
            
            if (empty($processedUrls)) {
                $this->helper->log("No suitable URLs found after processing paste input", 'warning');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('No suitable URLs found to analyze. Please make sure you pasted valid static or media URLs.')
                ]);
            }
            
            $count = count($processedUrls);
            $this->helper->log("Processed {$count} pasted URLs successfully", 'info');
            
            $this->messageManager->addSuccessMessage(
                __('Found %1 valid URLs from your input.', $count)
            );
            
            return $resultJson->setData([
                'success' => true,
                'urls' => $processedUrls,
                'message' => __('URL analysis completed.')
            ]);
        } catch (\Exception $e) {
            $this->helper->log('Error in DirectAnalyzeUrls::execute: ' . $e->getMessage(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred while analyzing URLs.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Process and normalize URLs
     *
     * @param array $urls
     * @return array
     */
    protected function processUrls(array $urls)
    {
        $result = [];
        $baseUrl = $this->helper->getBaseUrl();
        $baseUrlParsed = parse_url($baseUrl);
        $baseDomain = isset($baseUrlParsed['host']) ? $baseUrlParsed['host'] : '';
        
        foreach ($urls as $url) {
            // Skip empty URLs
            if (empty($url)) {
                continue;
            }
            
            // Check if URL is from our domain - this is important!
            if (strpos($url, 'http') === 0) {
                $urlParsed = parse_url($url);
                
                // Skip URLs from different domains
                if (isset($urlParsed['host']) && $urlParsed['host'] !== $baseDomain) {
                    $this->helper->log("Skipping URL from different domain: {$url}", 'debug');
                    continue;
                }
                
                // Extract the path
                if (isset($urlParsed['path'])) {
                    $url = $urlParsed['path'];
                } else {
                    continue;
                }
            }
            
            // Ensure URL starts with a slash
            if (strpos($url, '/') !== 0) {
                $url = '/' . $url;
            }
            
            // Keep only static and media URLs
            if (strpos($url, '/static/') === 0 || strpos($url, '/media/') === 0) {
                $result[] = $url;
            }
        }
        
        // Remove duplicates and sort
        $result = array_unique($result);
        sort($result);
        
        return $result;
    }
}