<?php
namespace MagoArab\CdnIntegration\Model;

use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\Github\Api as GithubApi;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class UrlValidator
{
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
     * @param Helper $helper
     * @param GithubApi $githubApi
     * @param Filesystem $filesystem
     * @param FileDriver $fileDriver
     */
    public function __construct(
        Helper $helper,
        GithubApi $githubApi,
        Filesystem $filesystem,
        FileDriver $fileDriver
    ) {
        $this->helper = $helper;
        $this->githubApi = $githubApi;
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
    }
    
    /**
     * Validate and auto-upload custom URLs
     *
     * @return array
     */
    public function validateAndUploadCustomUrls()
    {
        $customUrls = $this->helper->getCustomUrls();
        if (empty($customUrls)) {
            return ['success' => true, 'message' => 'No custom URLs defined.', 'details' => []];
        }
        
        $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        
        $results = [
            'success' => true,
            'processed' => 0,
            'uploaded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'already_on_github' => 0,
            'font_corrections' => 0,
            'details' => []
        ];
        
        foreach ($customUrls as $url) {
            $results['processed']++;
            
            // Skip empty URLs
            if (empty($url)) {
                continue;
            }
            
            // Normalize URL (remove domain if present)
            $normalizedUrl = $url;
            if (strpos($url, 'http') === 0) {
                $parsedUrl = parse_url($url);
                if (isset($parsedUrl['path'])) {
                    $normalizedUrl = $parsedUrl['path'];
                }
            }
            
            // Skip if not a static or media URL
            if (strpos($normalizedUrl, '/static/') !== 0 && strpos($normalizedUrl, '/media/') !== 0) {
                $results['skipped']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'skipped',
                    'message' => 'Not a static or media URL'
                ];
                continue;
            }
            
            // Determine local file path and remote path
            $localPath = '';
            $remotePath = '';
            
            if (strpos($normalizedUrl, '/static/') === 0) {
                $path = substr($normalizedUrl, 8); // Remove '/static/'
                $localPath = $staticDir . $path;
                $remotePath = $path;
            } elseif (strpos($normalizedUrl, '/media/') === 0) {
                $path = substr($normalizedUrl, 7); // Remove '/media/'
                $localPath = $mediaDir . $path;
                $remotePath = $path;
            }
            
            // CSS specific handling for font detection
            $extension = strtolower(pathinfo($normalizedUrl, PATHINFO_EXTENSION));
            if ($extension === 'css') {
                $cssResult = $this->processCssForFontValidation($normalizedUrl);
                
                if ($cssResult['scanned'] && $cssResult['missing_fonts'] > 0) {
                    // Process missing fonts with alternatives
                    foreach ($cssResult['details'] as $fontDetail) {
                        if (!$fontDetail['exists'] && !empty($fontDetail['alternatives'])) {
                            foreach ($fontDetail['alternatives'] as $altFontPath) {
                                // Get relative path for the font
                                $fontRelativePath = $this->getRelativePath($altFontPath);
                                
                                // Upload alternative font if it's not already on GitHub
                                if (!$this->checkPathExistsOnGithub($fontRelativePath)) {
                                    $success = $this->uploadFileToGithubWithPath(
                                        $altFontPath, 
                                        $fontRelativePath
                                    );
                                    
                                    if ($success) {
                                        $results['uploaded']++;
                                        $results['font_corrections']++;
                                        $results['details'][] = [
                                            'url' => $fontDetail['url'],
                                            'status' => 'uploaded',
                                            'message' => 'Font alternative uploaded: ' . basename($altFontPath)
                                        ];
                                    } else {
                                        $results['failed']++;
                                        $results['details'][] = [
                                            'url' => $fontDetail['url'],
                                            'status' => 'failed',
                                            'message' => 'Failed to upload font alternative: ' . basename($altFontPath)
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // Check if the file exists locally
            if (!$this->fileDriver->isExists($localPath)) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => 'File not found locally: ' . $localPath
                ];
                continue;
            }
            
            // Check if the file already exists on GitHub
            $existingFile = $this->githubApi->getContents($remotePath);
            
            if ($existingFile && isset($existingFile['sha'])) {
                $results['already_on_github']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'exists',
                    'message' => 'File already exists on GitHub'
                ];
                continue;
            }
            
            // Upload the file to GitHub
            $success = $this->githubApi->uploadFile($localPath, $remotePath);
            
            if ($success) {
                $results['uploaded']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'uploaded',
                    'message' => 'Successfully uploaded to GitHub'
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => 'Failed to upload to GitHub'
                ];
            }
        }
        
        $results['message'] = sprintf(
            'Processed %d URLs: %d uploaded, %d already on GitHub, %d failed, %d skipped, %d font corrections',
            $results['processed'],
            $results['uploaded'],
            $results['already_on_github'],
            $results['failed'],
            $results['skipped'],
            $results['font_corrections']
        );
        
        return $results;
    }
    
    /**
     * Check if a specific URL exists on GitHub
     * 
     * @param string $url
     * @return bool
     */
    public function checkUrlExistsOnGithub($url)
    {
        // Normalize URL
        $normalizedUrl = $url;
        if (strpos($url, 'http') === 0) {
            $parsedUrl = parse_url($url);
            if (isset($parsedUrl['path'])) {
                $normalizedUrl = $parsedUrl['path'];
            }
        }
        
        // Determine remote path
        $remotePath = '';
        
        if (strpos($normalizedUrl, '/static/') === 0) {
            $remotePath = substr($normalizedUrl, 8); // Remove '/static/'
        } elseif (strpos($normalizedUrl, '/media/') === 0) {
            $remotePath = substr($normalizedUrl, 7); // Remove '/media/'
        } else {
            return false; // Not a static or media URL
        }
        
        // Check if the file exists on GitHub
        $existingFile = $this->githubApi->getContents($remotePath);
        
        return ($existingFile && isset($existingFile['sha']));
    }
    
    /**
     * Check if a specific path exists on GitHub
     * 
     * @param string $path
     * @return bool
     */
    public function checkPathExistsOnGithub($path)
    {
        // Check if the file exists on GitHub
        $existingFile = $this->githubApi->getContents($path);
        
        return ($existingFile && isset($existingFile['sha']));
    }
    
    /**
     * Get local file path for a URL
     * 
     * @param string $url
     * @return string
     */
    public function getLocalPathForUrl($url)
    {
        // Normalize URL
        $normalizedUrl = $url;
        if (strpos($url, 'http') === 0) {
            $parsedUrl = parse_url($url);
            if (isset($parsedUrl['path'])) {
                $normalizedUrl = $parsedUrl['path'];
            }
        }
        
        // Skip if not a static or media URL
        if (strpos($normalizedUrl, '/static/') !== 0 && strpos($normalizedUrl, '/media/') !== 0) {
            return '';
        }
        
        try {
            $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
            
            // Determine local file path
            if (strpos($normalizedUrl, '/static/') === 0) {
                $path = substr($normalizedUrl, 8); // Remove '/static/'
                return $staticDir . $path;
            } elseif (strpos($normalizedUrl, '/media/') === 0) {
                $path = substr($normalizedUrl, 7); // Remove '/media/'
                return $mediaDir . $path;
            }
        } catch (\Exception $e) {
            $this->helper->log('Error getting file path for URL: ' . $e->getMessage(), 'error');
            return '';
        }
        
        return '';
    }
    
    /**
     * Get static directory path
     * @return string
     */
    public function getStaticDir()
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
    }

    /**
     * Get media directory path
     * @return string
     */
    public function getMediaDir()
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
    }
    
    /**
     * Get relative path from absolute path
     * 
     * @param string $absolutePath
     * @return string
     */
    public function getRelativePath($absolutePath)
    {
        $staticDir = $this->getStaticDir();
        $mediaDir = $this->getMediaDir();
        
        if (strpos($absolutePath, $staticDir) === 0) {
            return substr($absolutePath, strlen($staticDir));
        } elseif (strpos($absolutePath, $mediaDir) === 0) {
            return substr($absolutePath, strlen($mediaDir));
        }
        
        return basename($absolutePath);
    }
    
    /**
     * Validate and fix font file issues
     *
     * @param string $url
     * @return array
     */
    public function validateFontFile($url)
    {
        $result = [
            'status' => 'pending',
            'original_url' => $url,
            'corrected_urls' => [],
            'issues' => []
        ];

        // Normalize URL
        $normalizedUrl = $this->normalizeUrl($url);

        // Potential font file extensions
        $fontExtensions = ['woff', 'woff2', 'ttf', 'eot', 'otf'];
        $ext = pathinfo($normalizedUrl, PATHINFO_EXTENSION);

        if (!in_array(strtolower($ext), $fontExtensions)) {
            $result['status'] = 'skipped';
            $result['issues'][] = 'Not a font file';
            return $result;
        }

        // Determine local and potential alternative paths
        $localPath = $this->getLocalPathForUrl($url);

        if (empty($localPath)) {
            $result['status'] = 'error';
            $result['issues'][] = 'Could not determine local path';
            return $result;
        }

        // Check if file exists
        if (!file_exists($localPath)) {
            // Try to find alternative variations
            $alternatives = $this->findFontAlternatives($localPath);

            if (!empty($alternatives)) {
                $result['status'] = 'corrected';
                $result['corrected_urls'] = $alternatives;
                $result['issues'][] = 'Original file not found, alternatives suggested';
            } else {
                $result['status'] = 'error';
                $result['issues'][] = 'File not found and no alternatives available';
            }
        } else {
            // Perform additional checks on the font file
            $result['status'] = $this->performFontFileHealthCheck($localPath);
        }

        return $result;
    }

    /**
     * Find alternative font file variations
     *
     * @param string $originalPath
     * @return array
     */
    private function findFontAlternatives($originalPath)
    {
        $alternatives = [];
        
        // Get font directory
        $fontDir = dirname($originalPath);
        $fontBasename = basename($originalPath);
        
        if (!is_dir($fontDir)) {
            return $alternatives;
        }
        
        try {
            // Get all font files in the directory
            $files = glob($fontDir . '/*.{woff,woff2,ttf,eot,otf}', GLOB_BRACE);
            
            // Try to match by filename, removing version numbers and hashes
            $plainFontName = preg_replace('/\.(woff2?|ttf|eot|otf)$/', '', $fontBasename);
            $plainFontName = preg_replace('/-v\d+|-\d+|\.[a-f0-9]{8,}/', '', $plainFontName);
            
            foreach ($files as $file) {
                $fileBasename = basename($file);
                $plainFileName = preg_replace('/\.(woff2?|ttf|eot|otf)$/', '', $fileBasename);
                $plainFileName = preg_replace('/-v\d+|-\d+|\.[a-f0-9]{8,}/', '', $plainFileName);
                
                // Use similarity check to find matching fonts
                similar_text($plainFontName, $plainFileName, $percentage);
                if ($percentage > 70) { // 70% similarity threshold
                    $alternatives[] = $file;
                }
            }
        } catch (\Exception $e) {
            $this->helper->log('Error finding font alternatives: ' . $e->getMessage(), 'error');
        }
        
        return $alternatives;
    }

    /**
     * Perform health check on font file
     *
     * @param string $filePath
     * @return string
     */
    private function performFontFileHealthCheck($filePath)
    {
        // Check file size
        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            return 'error_empty_file';
        }

        if ($fileSize > 1024 * 1024) { // 1MB limit
            return 'warning_large_file';
        }

        // Optional: Use font parsing libraries for more advanced checks
        try {
            // Placeholder for potential font library validation
            // You might want to integrate a library like 'sfnt2woff' or php font parsing libraries
            return 'healthy';
        } catch (\Exception $e) {
            return 'error_parsing';
        }
    }

    /**
     * Normalize URL for consistent processing
     *
     * @param string $url
     * @return string
     */
    private function normalizeUrl($url)
    {
        // Remove query parameters
        $url = strtok($url, '?');
        
        // Ensure starts with /
        if (strpos($url, '/') !== 0) {
            $url = '/' . $url;
        }

        return $url;
    }
    
    /**
     * Process CSS files to find and validate font references
     * 
     * @param string $cssUrl
     * @return array
     */
    public function processCssForFontValidation($cssUrl)
    {
        $result = [
            'scanned' => false,
            'found_fonts' => 0,
            'valid_fonts' => 0,
            'missing_fonts' => 0,
            'details' => []
        ];
        
        $localPath = $this->getLocalPathForUrl($cssUrl);
        if (empty($localPath) || !file_exists($localPath)) {
            return $result;
        }
        
        $fontUrls = $this->scanCssForFonts($localPath);
        $result['scanned'] = true;
        $result['found_fonts'] = count($fontUrls);
        
        foreach ($fontUrls as $fontUrl) {
            $fontLocalPath = $this->getLocalPathForUrl($fontUrl);
            $fontExists = !empty($fontLocalPath) && file_exists($fontLocalPath);
            
            $fontResult = [
                'url' => $fontUrl,
                'exists' => $fontExists,
                'alternatives' => []
            ];
            
            if (!$fontExists) {
                $result['missing_fonts']++;
                
                // Try to find alternative font files
                $alternatives = $this->findFontAlternatives($fontLocalPath);
                $fontResult['alternatives'] = $alternatives;
                
                if (!empty($alternatives)) {
                    $fontResult['has_alternatives'] = true;
                    $result['valid_fonts']++;
                } else {
                    $fontResult['has_alternatives'] = false;
                }
            } else {
                $result['valid_fonts']++;
            }
            
            $result['details'][] = $fontResult;
        }
        
        return $result;
    }
    
    /**
     * Find font files referenced in CSS files
     * 
     * @param string $cssFilePath
     * @return array
     */
    public function scanCssForFonts($cssFilePath)
    {
        $fontUrls = [];
        
        if (!file_exists($cssFilePath)) {
            return $fontUrls;
        }
        
        try {
            $cssContent = file_get_contents($cssFilePath);
            
            // Match font URLs in the CSS
            $fontPatterns = [
                '/url\([\'"]?(\/[^"\'\)]+\.(woff2?|eot|ttf|otf))[\'"]?\)/i',
                '/@font-face\s*{[^}]*src:\s*url\([\'"]?(\/[^"\'\)]+\.(woff2?|eot|ttf|otf))[\'"]?\)/i',
                // Pattern for absolute URLs with domain
                '/url\([\'"]?(https?:\/\/[^"\'\)]+\.(woff2?|eot|ttf|otf))[\'"]?\)/i'
            ];
            
            foreach ($fontPatterns as $pattern) {
                if (preg_match_all($pattern, $cssContent, $matches)) {
                    foreach ($matches[1] as $fontUrl) {
                        $fontUrls[] = $fontUrl;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->helper->log('Error scanning CSS for fonts: ' . $e->getMessage(), 'error');
        }
        
        return array_unique($fontUrls);
    }
    
    /**
     * Upload a file to GitHub
     * 
     * @param string $url The original URL
     * @param string $localPath The local file path
     * @return bool
     */
    public function uploadFileToGithub($url, $localPath)
    {
        try {
            // Normalize URL and get remote path
            $normalizedUrl = $url;
            if (strpos($url, 'http') === 0) {
                $parsedUrl = parse_url($url);
                if (isset($parsedUrl['path'])) {
                    $normalizedUrl = $parsedUrl['path'];
                }
            }
            
            $remotePath = '';
            if (strpos($normalizedUrl, '/static/') === 0) {
                $remotePath = substr($normalizedUrl, 8); // Remove '/static/'
            } elseif (strpos($normalizedUrl, '/media/') === 0) {
                $remotePath = substr($normalizedUrl, 7); // Remove '/media/'
            } else {
                return false; // Not a static or media URL
            }
            
            // Check if file exists locally
            if (!file_exists($localPath)) {
                $this->helper->log('File does not exist locally: ' . $localPath, 'error');
                return false;
            }
            
            // Upload file to GitHub
            $success = $this->githubApi->uploadFile($localPath, $remotePath);
            
            if ($success) {
                $this->helper->log('Successfully uploaded file to GitHub: ' . $url, 'info');
                return true;
            } else {
                $this->helper->log('Failed to upload file to GitHub: ' . $url, 'error');
                return false;
            }
        } catch (\Exception $e) {
            $this->helper->log('Error uploading file to GitHub: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Upload a file to GitHub with a specific path
     * 
     * @param string $localPath The local file path
     * @param string $remotePath The remote path to use
     * @return bool
     */
    public function uploadFileToGithubWithPath($localPath, $remotePath)
    {
        try {
            // Check if file exists locally
            if (!file_exists($localPath)) {
                $this->helper->log('File does not exist locally: ' . $localPath, 'error');
                return false;
            }
            
            // Upload file to GitHub
            $success = $this->githubApi->uploadFile($localPath, $remotePath);
            
            if ($success) {
                $this->helper->log('Successfully uploaded file to GitHub: ' . $remotePath, 'info');
                return true;
            } else {
                $this->helper->log('Failed to upload file to GitHub: ' . $remotePath, 'error');
                return false;
            }
        } catch (\Exception $e) {
            $this->helper->log('Error uploading file to GitHub: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}