<?php

/**
 *
 * @version     $Id: flickrset.php 0.2 2014/03/01 olivier $
 * @package     Joomla.Platform
 * @subpackage  Plugin
 * @author      Olivier
 * @copyright   Copyright (C) 2005-2014 Open Source Matters. All rights reserved.
 * @license     GNU/GPL, see LICENSE.php
 *
 * Joomla! is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * 
 * See COPYRIGHT.php for copyright notices and details.
 */
// no direct access
defined('_JEXEC') or die('Restricted access');

// Import the FlickrsetPlugin base class for common methods
JLoader::import('components.com_flickrset4joomla.libraries.flickrset.plugin', JPATH_ADMINISTRATOR);

if (!class_exists('FlickrSetPlugin')) {
    return;
}

// Get Application handler
jimport('joomla.environment.browser');

class plgContentflickrset extends FlickrSetPlugin {

    // Loading the language file on instantiation
    protected $autoloadLanguage = true;
    
    // Flickr API url
    protected $flickrapiurl = 'https://api.flickr.com/services/rest/?';
    protected $flickrphotosetsgetInfomethod = 'flickr.photosets.getInfo';
    protected $flickrrestformat = 'php_serial';

    var $plg_name             = 'flickrset';
    // These are used when rendering the flickrset
    var $plg_version          = '';
    var $plg_copyrights_start = '';
    var $plg_copyrights_end   = '';
    // This is the tag where we look for in the article content
    var $plg_tag              = 'flickrset';
    // These are used when navigating with a mobile device
    var $plg_tag_button       = 'flickrsetbutton';
    var $plg_tag_link         = 'flickrsetlink';
    var $plg_link_display     = '';

    /**
     * Plugin that replaces {flickrset}-tags with flickr embeded code
     *  When on a mobile device we show an URL instead of a flash object
     *
     * @param   string   $context    The context of the content being passed to the plugin.
     * @param   mixed    &$article   A reference to the article that is being rendered by the view.
     * @param   mixed    &$params    A reference to an associative array of relevant parameters.
     * @param   integer  $limitstart An integer that determines the "page" of the content that is to be generated.
     *
     * @return  boolean        true on success.
     */
    function onContentPrepare($context, &$article, &$params, $limitstart) {
        // Don't run this plugin when the content is being indexed
        if ($context === 'com_finder.indexer') {
            return true;
        }

        // Check if plugin is enabled
        if (JPluginHelper::isEnabled('content', $this->plg_name) == false) {
            return true;
        }

        // Includes
        require(dirname(__FILE__) . DIRECTORY_SEPARATOR . $this->plg_name . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'sources.php');

        // Simple performance check to determine whether plugin should process further
        //  Verify if tag is found as a key in the newtagsource array
        $grabTags = str_replace("(", "", str_replace(")", "", implode(array_keys($newtagsource), "|")));

        $regex = '/\{(' . $grabTags . ')\}/i';
        $matchresult = preg_match($regex, $article->text);

        if ($matchresult == false) {
            return;
        }
        
        //Get the version number of the plugin
        $xml = JFactory::getXML(JPATH_PLUGINS . DS . 'content'. DS . $this->plg_name . DS . $this->plg_name .'.xml');
        $this->plg_version = $xml->version;
        $this->plg_copyrights_start = "\n\n<!-- \"FlickrSet\" Plugin version ".$this->plg_version." starts here -->\n";
        $this->plg_copyrights_end   = "\n<!-- \"FlickrSet\" Plugin version ".$this->plg_version." ends here -->\n\n";

        // Get plugin parameters
        $plgparam_flickrset_flickrid = trim($this->params->get('flickrset_flickrid'));
        $plgparam_flickrset_allowfullscreen = trim($this->params->get('flickrset_allowfullscreen', 'Y'));
        $plgparam_flickrset_objectwidth = trim($this->params->get('flickrset_objectwidth', 400));
        $plgparam_flickrset_objectheight = trim($this->params->get('flickrset_objectheight', 300));
        $plgparam_flickrset_flickrapikey = trim($this->params->get('flickrset_flickrapikey'));
        $plgparam_flickrset_mobile_type = trim($this->params->get('flickrset_mobile_type', 'L'));

        // Plugin wont be executed when default flickerid is empty
        if ($plgparam_flickrset_flickrid == '') {
            return true;
        }
        
        // Only when we are sure that plugin needs to be executed get mobile input
        $browser = &JBrowser::getInstance();
        $agent = $browser->getAgentString();
        
        // Load plugin language,stylesheet file
        JPlugin::loadLanguage('plg_content_flickrset',JPATH_ADMINISTRATOR);
        JHtml::stylesheet('plg_content_flickrset/plg_content_flickrset.css', array(),true);
        
        // Expression to search for (positions)
        $regex = "/{" . $this->plg_tag . "}.*?{\/" . $this->plg_tag . "}/i";

        // Determine if there are instances of $plg_tag and put them in $matches
        //  when no instances found do not perform tag replacement
        if (preg_match_all($regex, $article->text, $matches)) {
            // An array of all different elements used in the flickerset template
            $TmplElmtParams = array(
                    "{PLAYERID}",
                    "{FLICKR_SETID}",
                    "{FLICKRID}",
                    "{LANGUAGE}",
                    "{OBJECT_WIDTH}",
                    "{OBJECT_HEIGHT}",
                    "{ALLOWFULLSCREEN}",
                    "{LINK_DISPLAY}");

           // Determine which tagsource to use depending on mobile device
           if ($browser->isMobile() || stristr($agent, 'mobile')) {
              // Show flickerset depending on the plugin mobile setting
              if ($plgparam_flickrset_mobile_type == 'L') {
                 $usedtagsource = $newtagsource[$this->plg_tag_link];
                 } else {
                   $usedtagsource = $newtagsource[$this->plg_tag_button];
                 }
              } else {
                $usedtagsource = $newtagsource[$this->plg_tag];
              }
              
            // Get the current language
            $lang = JFactory::getLanguage();

            // start the replace loop
            foreach ($matches[0] as $key => $match) {
                // Remove the tags
                $tagcontent = preg_replace("/{.+?}/", "", $match);

                // Get an array of parameters
                // order of parameters: flickersetid|width|height|flickrid|AllowFullScreen
                $tagparams = explode('|', $tagcontent);

                // Get the flickersetid strip html/php tags
                $tagparam_flickersetid = trim(strip_tags($tagparams[0]));

                // Get the width and height tags
                $final_objectwidth = (@$tagparams[1]) ? $tagparams[1] : $plgparam_flickrset_objectwidth;
                $final_objectheight = (@$tagparams[2]) ? $tagparams[2] : $plgparam_flickrset_objectheight;

                // Determine the flickrid
                $final_flickrid = (@$tagparams[3]) ? $tagparams[3] : $plgparam_flickrset_flickrid;

                // Determine the allow fullscreen
                $tag_allowfullscreen = (@$tagparams[4]) ? $tagparams[4] : $plgparam_flickrset_allowfullscreen;
                $final_allowfullscreen = (@$tag_allowfullscreen === 'Y') ? 'true' : 'false';

                // Set a unique ID
                $flickerset_playerID = 'FlickrSetID_' . substr(md5($tagparam_flickersetid), 1, 10) . '_' . rand();

                // Construct the link name when on mobile device
                if ($browser->isMobile() || stristr($agent, 'mobile')) {
                    $flickrapi = $this->flickrapiurl.'method='.$this->flickrphotosetsgetInfomethod.'&api_key='.$plgparam_flickrset_flickrapikey.'&photoset_id='.$tagparam_flickersetid.'&format='.$this->flickrrestformat;
                    $resp = file_get_contents($flickrapi);
                    $resp_obj = unserialize($resp);
                    if($resp_obj['stat'] == 'ok') {
                       $flickerset_title = $resp_obj['photoset']['title']['_content'];
                    } else {
                        $flickerset_title = '';
                    }
                    $this->plg_link_display = JText::sprintf('PLG_FLICKERSET_PROMPT_LINK_DISPLAY',$flickerset_title);
                    $this->log('Link display: '.$this->plg_link_display);
                };

                // An array of all different elements values used in the flickerset template
                $TmplElmtParamValues = array(
                    $flickerset_playerID,
                    $tagparam_flickersetid,
                    $final_flickrid,
                    $lang->getTag(),
                    $final_objectwidth,
                    $final_objectheight,
                    $final_allowfullscreen,
                    $this->plg_link_display
                );

                // Perform the actual tag replacement
                $convertedtag = $this->plg_copyrights_start.JFilterOutput::ampReplace(str_replace($TmplElmtParams, $TmplElmtParamValues, $usedtagsource)).$this->plg_copyrights_end;

                // Output
                $regex = "/{" . $this->plg_tag . "}" . preg_quote($tagcontent) . "{\/" . $this->plg_tag . "}/i";
                $article->text = preg_replace($regex, $convertedtag, $article->text);

            } // End foreach replacing loop
        } else { //when code comes here, no flickrset tags found to replace
        }// End if find all instance of $plg_tag

        return true;
    }

}

?>
