<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\UrlValidator;

class ValidateUrls extends Action
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
     * @var UrlValidator
     */
    protected $urlValidator;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param UrlValidator $urlValidator
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        UrlValidator $urlValidator
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->urlValidator = $urlValidator;
    }

    /**
     * Validate and auto-upload custom URLs with batch processing
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
            
            $this->helper->log('Starting validation of custom URLs', 'info');
            
            // Check if we're processing a specific batch
            $batchUrls = $this->getRequest()->getParam('batch_urls');
            
            if ($batchUrls) {
                // Process batch of URLs
                $batchIndex = (int)$this->getRequest()->getParam('batch_index', 0);
                $totalBatches = (int)$this->getRequest()->getParam('total_batches', 1);
                
                $this->helper->log("Processing batch {$batchIndex} of {$totalBatches}", 'info');
                
                // Decode the URLs for this batch
                $urls = json_decode($batchUrls, true);
                
                if (!is_array($urls)) {
                    return $resultJson->setData([
                        'success' => false,
                        'message' => __('Invalid URL format in batch.')
                    ]);
                }
                
                // Process this batch
                $batchResults = $this->validateUrlBatch($urls);
                
                return $resultJson->setData([
                    'success' => true,
                    'batch_index' => $batchIndex,
                    'total_batches' => $totalBatches,
                    'batch_results' => $batchResults
                ]);
            } else {
                // Regular full validation
                $results = $this->urlValidator->validateAndUploadCustomUrls();
                
                if ($results['uploaded'] > 0) {
                    $this->messageManager->addSuccessMessage(__('Uploaded %1 files to GitHub that were not previously uploaded.', $results['uploaded']));
                }
                
                if ($results['failed'] > 0) {
                    $this->messageManager->addWarningMessage(__('Failed to upload %1 files. See details for more information.', $results['failed']));
                }
                
                $this->helper->log('URL validation completed: ' . $results['message'], 'info');
                
                return $resultJson->setData([
                    'success' => true,
                    'results' => $results,
                    'message' => $results['message']
                ]);
            }
        } catch (\Exception $e) {
            $this->helper->log('Error validating URLs: ' . $e->getMessage(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred while validating URLs.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Validate a batch of URLs
     *
     * @param array $urls
     * @return array
     */
protected function validateUrlBatch(array $urls)
{
    $results = [
        'processed' => 0,
        'success' => 0,
        'failed' => 0,
        'exists' => 0,
        'font_corrections' => 0, // New field for font-specific corrections
        'details' => []
    ];
    
    foreach ($urls as $url) {
        $results['processed']++;
        
        try {
            // 1. Check if URL exists on GitHub
            $existsOnGithub = $this->urlValidator->checkUrlExistsOnGithub($url);
            
            if ($existsOnGithub) {
                $results['exists']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'exists',
                    'message' => __('File already exists on GitHub')
                ];
                continue;
            }
            
            // 2. Perform font-specific validation
            $fontValidation = $this->urlValidator->validateFontFile($url);
            
            // Handle font-specific scenarios
            switch ($fontValidation['status']) {
                case 'corrected':
                    $results['font_corrections']++;
                    $results['details'][] = [
                        'url' => $url,
                        'status' => 'font_corrected',
                        'message' => __('Font file corrected: %1', implode(', ', $fontValidation['issues'])),
                        'alternatives' => $fontValidation['corrected_urls']
                    ];
                    // Use the first alternative URL for upload
                    $localPath = reset($fontValidation['corrected_urls']);
                    break;
                
                case 'error':
                    $results['failed']++;
                    $results['details'][] = [
                        'url' => $url,
                        'status' => 'failed',
                        'message' => __('Font file validation failed: %1', implode(', ', $fontValidation['issues']))
                    ];
                    continue 2; // Skip to next iteration
                
                default:
                    // Get local path for the URL
                    $localPath = $this->urlValidator->getLocalPathForUrl($url);
            }
            
            // 3. Verify local file path
            if (empty($localPath)) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => __('Could not determine local path for URL')
                ];
                continue;
            }
            
            // 4. Check if file exists locally
            if (!file_exists($localPath)) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => __('File not found locally: %1', $localPath)
                ];
                continue;
            }
            
            // 5. Upload file to GitHub
            $success = $this->urlValidator->uploadFileToGithub($url, $localPath);
            
            if ($success) {
                $results['success']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'uploaded',
                    'message' => __('Successfully uploaded to GitHub')
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => __('Failed to upload to GitHub')
                ];
            }
        } catch (\Exception $e) {
            $this->helper->log('Error processing URL ' . $url . ': ' . $e->getMessage(), 'error');
            $results['failed']++;
            $results['details'][] = [
                'url' => $url,
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
        
        // Maintain the small delay between operations
        usleep(100000); // 100ms
    }
    
    return $results;
}
}