<?php
namespace MagoArab\CdnIntegration\Model\Github;

use MagoArab\CdnIntegration\Helper\Data as Helper;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class Api
{
    /**
     * GitHub API base URL
     */
    const API_URL = 'https://api.github.com';

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Helper $helper
     * @param Curl $curl
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        Helper $helper,
        Curl $curl,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->helper = $helper;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Set the common headers for GitHub API requests
     *
     * @return void
     */
    protected function setHeaders()
    {
        // Reset curl instance to avoid conflicts
        $this->curl = new Curl();
        
        $token = $this->helper->getGithubToken();
        
        if (empty($token)) {
            $this->helper->log('GitHub token is not configured', 'error');
            throw new \Exception('GitHub token is not configured');
        }
        
        $this->helper->log('Setting GitHub headers with token: ' . substr($token, 0, 4) . '...', 'debug');
        
        // IMPORTANT: Correct format for GitHub authorization header
        $this->curl->addHeader('Authorization', 'token ' . $token);
        $this->curl->addHeader('User-Agent', 'MagoArab-Magento-CDN');
        $this->curl->addHeader('Accept', 'application/vnd.github.v3+json');
        $this->curl->addHeader('Content-Type', 'application/json');
        
        // Configure curl for better performance
        $this->curl->setOption(CURLOPT_TIMEOUT, 30);
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
        $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
        
        // Verbose debugging
        $this->curl->setOption(CURLOPT_VERBOSE, true);
        
        // Log all cURL options for debugging
        $this->helper->log('cURL options configured for GitHub API', 'debug');
    }

    /**
     * Upload a file to the repository
     * If the file already exists, it will be updated
     *
     * @param string $localPath The local path to the file
     * @param string $remotePath The remote path in the repository
     * @return bool
     */
    public function uploadFile($localPath, $remotePath)
    {
        try {
            $this->helper->log("Starting upload of file: {$localPath} to {$remotePath}", 'info');
            
            if (!file_exists($localPath)) {
                $this->helper->log("Local file does not exist: {$localPath}", 'error');
                return false;
            }

            // Log file metadata
            $fileSize = filesize($localPath);
            $fileType = mime_content_type($localPath);
            $this->helper->log("File size: {$fileSize} bytes, File type: {$fileType}", 'debug');
            
            // Read file content with detailed error handling
            $fileContent = @file_get_contents($localPath);
            if ($fileContent === false) {
                $error = error_get_last();
                $this->helper->log("Failed to read local file: {$localPath}. Error: " . print_r($error, true), 'error');
                return false;
            }
            
            $this->helper->log("Successfully read file content, length: " . strlen($fileContent) . " bytes", 'debug');
            
            // Make sure remotePath doesn't start with a slash
            $remotePath = ltrim($remotePath, '/');
            $this->helper->log("Normalized remote path: {$remotePath}", 'debug');
            
            // Check if the file already exists in the repository
            $this->helper->log("Checking if file already exists in repository", 'debug');
            $existingFile = $this->getContents($remotePath);
            $sha = null;
            
            if ($existingFile && isset($existingFile['sha'])) {
                $sha = $existingFile['sha'];
                $this->helper->log("File already exists with SHA: {$sha}", 'debug');
            } else {
                $this->helper->log("File does not exist in repository", 'debug');
            }

            // Create the commit message
            $fileName = basename($localPath);
            $message = $sha 
                ? "Update {$fileName} via MagoArab CDN Integration" 
                : "Add {$fileName} via MagoArab CDN Integration";
                
            $this->helper->log("Commit message: {$message}", 'debug');

            // Create or update the file in the repository
            $result = $this->createOrUpdateFile($remotePath, $fileContent, $message, $sha);
            
            if ($result) {
                $this->helper->log("Successfully uploaded file to GitHub: {$remotePath}", 'info');
            } else {
                $this->helper->log("Failed to upload file to GitHub: {$remotePath}", 'error');
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->helper->log(
                "Exception when uploading file: {$localPath} to {$remotePath}. Error: " . $e->getMessage() . "\n" .
                "Stack trace: " . $e->getTraceAsString(),
                'error'
            );
            return false;
        }
    }

    /**
     * Get the repository contents for a path
     *
     * @param string $path
     * @return array|bool
     */
    public function getContents($path = '')
    {
        try {
            $username = $this->helper->getGithubUsername();
            $repository = $this->helper->getGithubRepository();
            $branch = $this->helper->getGithubBranch();
            
            $this->helper->log("Getting repository contents for path: {$path}", 'debug');
            $this->helper->log("GitHub account: {$username}/{$repository}, branch: {$branch}", 'debug');
            
            if (empty($username) || empty($repository)) {
                $this->helper->log('GitHub username or repository is not configured', 'error');
                throw new \Exception('GitHub username or repository is not configured');
            }
            
            $url = self::API_URL . "/repos/{$username}/{$repository}/contents/{$path}";
            if (!empty($branch)) {
                $url .= "?ref={$branch}";
            }
            
            $this->helper->log("GitHub API URL: {$url}", 'debug');
            
            $this->setHeaders();
            $this->curl->get($url);
            
            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();
            
            $this->helper->log("GitHub API response code: {$statusCode}", 'debug');
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->helper->log("Successfully got contents from GitHub", 'debug');
                return $this->json->unserialize($response);
            } else if ($statusCode == 404) {
                // File doesn't exist, not an error
                $this->helper->log("Path not found on GitHub: {$path}", 'debug');
                return false;
            }
            
            $this->helper->log("Failed to get contents. Status: {$statusCode}, Response: {$response}", 'error');
            return false;
        } catch (\Exception $e) {
            $this->helper->log(
                "Exception when getting contents for path: {$path}. Error: " . $e->getMessage() . "\n" .
                "Stack trace: " . $e->getTraceAsString(),
                'error'
            );
            return false;
        }
    }

    /**
     * Create or update a file in the repository
     *
     * @param string $path The path to the file
     * @param string $content The file content (will be base64 encoded)
     * @param string $message The commit message
     * @param string|null $sha The blob SHA if updating an existing file
     * @return bool
     */
    public function createOrUpdateFile($path, $content, $message, $sha = null)
    {
        try {
            $username = $this->helper->getGithubUsername();
            $repository = $this->helper->getGithubRepository();
            $branch = $this->helper->getGithubBranch();
            
            $this->helper->log("Creating/updating file on GitHub: {$path}", 'debug');
            
            if (empty($username) || empty($repository)) {
                $this->helper->log('GitHub username or repository is not configured', 'error');
                throw new \Exception('GitHub username or repository is not configured');
            }
            
            $url = self::API_URL . "/repos/{$username}/{$repository}/contents/{$path}";
            $this->helper->log("GitHub API URL: {$url}", 'debug');
            
            $this->setHeaders();
            
            // Base64 encode content with detailed error handling
            $encodedContent = base64_encode($content);
            $this->helper->log("Base64 encoded content length: " . strlen($encodedContent), 'debug');
            
            if (empty($encodedContent) && !empty($content)) {
                $this->helper->log("Failed to base64 encode file content", 'error');
                return false;
            }
            
            $data = [
                'message' => $message,
                'content' => $encodedContent,
                'branch' => $branch
            ];
            
            // If SHA is provided, it means we're updating an existing file
            if ($sha) {
                $data['sha'] = $sha;
                $this->helper->log("Updating existing file with SHA: {$sha}", 'debug');
            } else {
                $this->helper->log("Creating new file", 'debug');
            }
            
            // Always use PUT for GitHub API to create/update file
            $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
            
            try {
                $jsonData = $this->json->serialize($data);
                $this->helper->log("JSON data length: " . strlen($jsonData), 'debug');
            } catch (\Exception $e) {
                $this->helper->log("Failed to serialize JSON data: " . $e->getMessage(), 'error');
                return false;
            }
            
            // Log request details
            $this->helper->log("Making PUT request to GitHub API", 'debug');
            
            // Make the request
            $this->curl->post($url, $jsonData);
            
            $statusCode = $this->curl->getStatus();
            $response = $this->curl->getBody();
            
            $this->helper->log("GitHub API create/update status: {$statusCode}", 'debug');
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->helper->log("Successfully created/updated file on GitHub", 'info');
                return true;
            }
            
            // Log detailed error information
            $this->helper->log("Failed to create/update file. Status: {$statusCode}, Response: {$response}", 'error');
            
            if (!empty($response)) {
                try {
                    $responseData = $this->json->unserialize($response);
                    if (isset($responseData['message'])) {
                        $this->helper->log("GitHub error message: " . $responseData['message'], 'error');
                    }
                    if (isset($responseData['errors']) && is_array($responseData['errors'])) {
                        foreach ($responseData['errors'] as $error) {
                            $this->helper->log("GitHub error detail: " . print_r($error, true), 'error');
                        }
                    }
                } catch (\Exception $e) {
                    $this->helper->log("Response parsing error: " . $e->getMessage(), 'error');
                }
            }
            
            return false;
        } catch (\Exception $e) {
            $this->helper->log(
                "Exception when creating/updating file: {$path}. Error: " . $e->getMessage() . "\n" .
                "Stack trace: " . $e->getTraceAsString(),
                'error'
            );
            return false;
        }
    }

    /**
     * Test GitHub connection and permissions
     *
     * @return bool
     */
    public function testConnection()
    {
        try {
            $username = $this->helper->getGithubUsername();
            $repository = $this->helper->getGithubRepository();
            
            $this->helper->log("Testing GitHub connection for {$username}/{$repository}", 'info');
            
            if (empty($username) || empty($repository)) {
                $this->helper->log('GitHub username or repository is not configured', 'error');
                return false;
            }
            
            // Build URL to check repository permissions directly
            $url = self::API_URL . "/repos/{$username}/{$repository}";
            $this->helper->log("Testing GitHub connection: GET {$url}", 'info');
            
            $this->setHeaders();
            
            // Make a single request to get repository info
            $this->curl->get($url);
            
            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();
            
            $this->helper->log("GitHub connection test response code: {$statusCode}", 'info');
            
            if ($statusCode >= 200 && $statusCode < 300) {
                try {
                    $responseData = $this->json->unserialize($response);
                    
                    // Log repository details
                    $this->helper->log("Repository info: " . print_r($responseData, true), 'debug');
                    
                    // Check if we have push permission directly from the repository data
                    if (isset($responseData['permissions']) && 
                        isset($responseData['permissions']['push']) && 
                        $responseData['permissions']['push'] === true) {
                        
                        $this->helper->log("GitHub permissions test successful: Read and Write access confirmed", 'info');
                        return true;
                    } else {
                        $this->helper->log("GitHub permissions test failed: Write access not confirmed", 'error');
                        if (isset($responseData['permissions'])) {
                            $this->helper->log("Permissions: " . print_r($responseData['permissions'], true), 'error');
                        }
                        return false;
                    }
                } catch (\Exception $e) {
                    $this->helper->log("Failed to parse response: " . $e->getMessage(), 'error');
                    return false;
                }
            }
            
            $this->helper->log("GitHub API error: Status {$statusCode}, Response: {$response}", 'error');
            return false;
        } catch (\Exception $e) {
            $this->helper->log(
                "GitHub connection test exception: " . $e->getMessage() . "\n" .
                "Stack trace: " . $e->getTraceAsString(),
                'error'
            );
            return false;
        }
    }
}