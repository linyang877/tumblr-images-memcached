<?php
/**
 * Created by PhpStorm.
 * User: Youi
 * Date: 2015-12-09
 * Time: 21:53
 */
class handler {

    /**
     * @var mc|null $mc
     */
    private static $mc;

    public static function loadMemcached($mc = null) {
        !static::$mc && (static::$mc = $mc ? $mc : (new mc()));
    }

    /**
     * get post identity params from url
     * @param string $url
     * @return array|bool params for constructing api query array('post_domain'=>'xx.tumblr.com', 'post_id'=>xxxx)
     */
    private static function parseUrlParam($url) {
        if (preg_match('<https?://(.+)/post/(\d+)>', $url, $match)) {
            return array(
                'post_domain' => $match[1],
                'post_id'     => $match[2]
            );
        } else {
            return false;
        }
    }

    public static function handle($url) {

        $postParam = static::parseUrlParam($url);
        $recordForNextTime = null;

        // try to process it
        try {

            // a valid tumblr url given
            if ($postParam) {
                $quickInfo = Input::fetchQuickResponseInfoFromCache($postParam);

                // quick response info found
                if ($quickInfo) {
                    syslog(LOG_INFO, "Quick Response.");

                    //make quick response
                    switch ($quickInfo['type']) {
                        case 'html':
                            Output::echoHtmlFile($quickInfo['content']);
                            break;
                        case 'video':
                        case 'singlePhoto':
                            Output::redirect($quickInfo['content']);
                            break;
                        case 'error':
                            Output::echoTxtFile($quickInfo['content']);
                            break;
                    }
                }

                // no quick response found, we got to process it
                else {
                    $postJSON = Input::fetchPostInfoFromCache($postParam);
                    $postJSON = $postJSON ? $postJSON : Input::queryTumblrApi($postParam);

                    // post json gotten
                    if ($postJSON) {

                        //save post info to memcached
                        Output::writePostInfoToCache($postParam, $postJSON);

                        $postInfo = $postJSON['posts'][0];
                        $postType = Content::parsePostType($postInfo);
                        $parserName = 'parse' . ucfirst($postType);

                        switch ($postType) {
                            case 'answer':
                            case 'link':
                            case 'regular':
                            case 'quote':
                                $output = Content::$parserName($postInfo);
                                Output::echoHtmlFile($output);
                                $recordForNextTime = array(
                                    'type' => 'html',
                                    'content' => $output
                                );
                                break;
                            case 'video':
                                $output = Content::$parserName($postInfo);

                                // video source parsed
                                if ($output) {
                                    Output::redirect($output);
                                    $recordForNextTime = array(
                                        'type' => 'video',
                                        'content' => $output
                                    );
                                }

                                // no video parsed
                                else {
                                    $errMsg = "Can't not parse video post, maybe it's too complicated to get the video source out.";
                                    throw new Exception($errMsg);
                                }
                                break;
                            case 'unknow':
                            case 'photo':
                            default:
                                $photoUrls = Content::$parserName($postInfo);
                                $photoCount = count($photoUrls);

                                // photo found
                                if ($photoCount > 0) {
                                    // one photo
                                    if ($photoCount === 1) {
                                        Output::redirect($photoUrls[0]);

                                        $recordForNextTime = array(
                                            'type' => 'singlePhoto',
                                            'content' => $photoUrls[0]
                                        );
                                    }

                                    // multi photo
                                    else {
                                        $imagesFromCache = Input::fetchImagesFromCache($photoUrls);

                                        // survey variables
                                        {
                                            $total = count($photoUrls);
                                            $cached = count($imagesFromCache);
                                            $fetched = 0;
                                            $startTime = microtime(true);
                                        }

                                        // get images
                                        $imagesContainer = array_fill_keys($photoUrls, null);
                                        $randomOrder = array_values($photoUrls); shuffle($randomOrder);
                                        foreach ($randomOrder as $imgUrl) {
                                            $fileName = basename($imgUrl);

                                            // image in cache found
                                            if (isset($imagesFromCache[$fileName])) {
                                                $imagesContainer[$imgUrl] = &$imagesFromCache[$fileName];
                                            }

                                            // not in cache
                                            else {
                                                $imagesContainer[$imgUrl] = Input::fetchImageFromNetwork($imgUrl); // fetch from network
                                                static::$mc->singleSet($fileName, $imagesContainer[$imgUrl]); // write to cache

                                                $fetched++;
                                            }
                                        }

                                        // output
                                        $zipPack = Content::getImagesZipPack($imagesContainer);
                                        Output::echoZipFile($zipPack);

                                        // survey record
                                        $timeUsed = number_format(microtime(true) - $startTime, 3, '.', '');
                                        syslog(LOG_INFO, "Total: $total, From cache: $cached, From network: $fetched, Time used: {$timeUsed}s");

                                        // refresh cache
                                        static::$mc->touchKeys(array_keys($imagesFromCache));
                                        //Output::writeImagesToCache($images, array_keys($imagesFromCache));
                                    }
                                }

                                // no photo found
                                else {
                                    $errMsg = "No images found in the tumblr post.";
                                    throw new Exception($errMsg);
                                }

                                break;
                        }

                    }

                    // not post json back from tumblr
                    else {
                        $postParam = false; //don't write quick response
                        $errMsg = 'No post info back from Tumblr.';
                        throw new Exception($errMsg);
                    }
                }

            }

            // not a valid tumblr url
            else {
                $errMsg = "Not a valid tumblr URL.";
                throw new Exception($errMsg);
            }

        }
        // catch error, generate and output error record
        catch (Exception $e) {

            $errText = Content::getErrorText($e->getMessage());

            $recordForNextTime = array(
                'type' => 'error',
                'content' => $errText
            );

            Output::echoTxtFile($errText);

        }
        // write error record or quick response
        finally {$postParam && $recordForNextTime && Output::writeQuickResponseInfoToCache($postParam, $recordForNextTime);}

        return true;
    }
}