<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\Github\Api as GithubApi;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class UploadToGithub extends Action
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
     * @var GithubApi
     */
    protected $githubApi;
    
    /**
     * @var Filesystem
     */
    protected $filesystem;
    
    /**
     * @var FileDriver
     */
    protected $fileDriver;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param GithubApi $githubApi
     * @param Filesystem $filesystem
     * @param FileDriver $fileDriver
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        GithubApi $githubApi,
        Filesystem $filesystem,
        FileDriver $fileDriver
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->githubApi = $githubApi;
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
    }

    /**
     * Upload files to GitHub
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
            
            // Verify GitHub credentials
            $this->helper->log('Checking GitHub credentials', 'debug');
            if (!$this->checkGithubCredentials()) {
                $this->helper->log('GitHub credentials check failed', 'error');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('GitHub credentials are not properly configured. Please check your settings and test the connection first.')
                ]);
            }
            
            $this->helper->log('GitHub credentials check passed', 'debug');
            
            $urls = $this->getRequest()->getParam('urls');
            if (empty($urls)) {
                $this->helper->log('No URLs provided for upload', 'error');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('No URLs provided for upload.')
                ]);
            }
            
            $this->helper->log('Decoding URLs JSON', 'debug');
            $urls = json_decode($urls, true);
            if (!is_array($urls)) {
                $this->helper->log('Invalid URL format: not an array', 'error');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Invalid URL format.')
                ]);
            }
            
            $this->helper->log('Getting file system directories', 'debug');
            $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
            
            $this->helper->log('Static directory: ' . $staticDir, 'debug');
            $this->helper->log('Media directory: ' . $mediaDir, 'debug');
            
            $results = [
                'total' => count($urls),
                'success' => 0,
                'failed' => 0,
                'details' => []
            ];
            
            $this->helper->log('Starting to process ' . count($urls) . ' URLs', 'info');
            
            // Process each URL one by one
            foreach ($urls as $url) {
                $this->helper->log('Processing URL: ' . $url, 'debug');
                
                try {
                    // Determine local file path
                    $localPath = '';
                    $remotePath = '';
                    
                    if (strpos($url, '/static/') === 0) {
                        $path = substr($url, 8); // Remove '/static/'
                        $localPath = $staticDir . $path;
                        $remotePath = $path;
                        $this->helper->log('Static file detected. Local path: ' . $localPath, 'debug');
                    } elseif (strpos($url, '/media/') === 0) {
                        $path = substr($url, 7); // Remove '/media/'
                        $localPath = $mediaDir . $path;
                        $remotePath = $path;
                        $this->helper->log('Media file detected. Local path: ' . $localPath, 'debug');
                    } else {
                        // Skip unsupported URLs
                        $this->helper->log('Unsupported URL format: ' . $url, 'warning');
                        $results['failed']++;
                        $results['details'][] = [
                            'url' => $url,
                            'success' => false,
                            'message' => __('Unsupported URL format.')
                        ];
                        continue;
                    }
                    
                    // Check if file exists
                    if (!$this->fileDriver->isExists($localPath)) {
                        $this->helper->log('File not found: ' . $localPath, 'error');
                        $results['failed']++;
                        $results['details'][] = [
                            'url' => $url,
                            'success' => false,
                            'message' => __('File not found: %1', $localPath)
                        ];
                        continue;
                    }
                    
                    $this->helper->log('File exists: ' . $localPath, 'debug');
                    
                    // Check file size and type
                    try {
                        $fileStats = $this->fileDriver->stat($localPath);
                        $fileSize = $fileStats['size'];
                        $this->helper->log('File size: ' . $fileSize . ' bytes', 'debug');
                        
                        if ($fileSize > 10 * 1024 * 1024) { // 10MB limit
                            $this->helper->log('File too large: ' . $localPath . ' (' . $fileSize . ' bytes)', 'warning');
                            $results['failed']++;
                            $results['details'][] = [
                                'url' => $url,
                                'success' => false,
                                'message' => __('File too large: %1 (max size: 10MB)', $localPath)
                            ];
                            continue;
                        }
                        
                        if ($fileSize === 0) {
                            $this->helper->log('File is empty: ' . $localPath, 'warning');
                            $results['failed']++;
                            $results['details'][] = [
                                'url' => $url,
                                'success' => false,
                                'message' => __('File is empty: %1', $localPath)
                            ];
                            continue;
                        }
                    } catch (\Exception $e) {
                        $this->helper->log('Error checking file stats: ' . $e->getMessage(), 'error');
                    }
                    
                    // Attempt to read the file content for validation
                    $fileContent = $this->fileDriver->fileGetContents($localPath);
                    if (empty($fileContent) && $fileSize > 0) {
                        $this->helper->log('Warning: File content is empty despite non-zero size', 'warning');
                    }
                    
                    $this->helper->log('Attempting to upload file to GitHub', 'debug');
                    
                    // Upload file to GitHub
                    $success = $this->githubApi->uploadFile($localPath, $remotePath);
                    
                    if ($success) {
                        $this->helper->log('Successfully uploaded ' . $url . ' to GitHub', 'info');
                        $results['success']++;
                        $results['details'][] = [
                            'url' => $url,
                            'success' => true,
                            'message' => __('Successfully uploaded to GitHub')
                        ];
                    } else {
                        $this->helper->log('Failed to upload ' . $url . ' to GitHub', 'error');
                        $results['failed']++;
                        $results['details'][] = [
                            'url' => $url,
                            'success' => false,
                            'message' => __('Failed to upload to GitHub. Check logs for details.')
                        ];
                    }
                } catch (\Exception $e) {
                    $this->helper->log('Exception processing URL ' . $url . ': ' . $e->getMessage(), 'error');
                    $results['failed']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                }
            }
            
            // Create success or failure message
            if ($results['failed'] > 0 && $results['success'] > 0) {
                $message = __('Upload completed with issues: %1 successful, %2 failed, %3 total.', 
                    $results['success'], 
                    $results['failed'], 
                    $results['total']
                );
                $this->helper->log($message, 'info');
            } else if ($results['failed'] > 0 && $results['success'] === 0) {
                $message = __('Upload failed for all files. Check logs for details.');
                $this->helper->log($message, 'error');
            } else {
                $message = __('All %1 files were successfully uploaded to GitHub.', $results['success']);
                $this->helper->log($message, 'info');
            }
            
            $this->messageManager->addSuccessMessage($message);
            
            return $resultJson->setData([
                'success' => true,
                'results' => $results,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            $this->helper->log('Error uploading files to GitHub: ' . $e->getMessage(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred while uploading files to GitHub.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Check if GitHub credentials are properly configured
     *
     * @return bool
     */
    private function checkGithubCredentials()
    {
        $username = $this->helper->getGithubUsername();
        $repository = $this->helper->getGithubRepository();
        $token = $this->helper->getGithubToken();
        
        if (empty($username) || empty($repository) || empty($token)) {
            return false;
        }
        
        return true;
    }
}