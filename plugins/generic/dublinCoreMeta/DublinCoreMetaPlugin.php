<?php

/**
 * @file plugins/generic/dublinCoreMeta/DublinCoreMetaPlugin.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DublinCoreMetaPlugin
 * @brief Inject Dublin Core meta tags into article views to facilitate indexing.
 */

namespace APP\plugins\generic\dublinCoreMeta;

use APP\facades\Repo;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class DublinCoreMetaPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                Hook::add('ArticleHandler::view', [&$this, 'articleView']);
            }
            return true;
        }
        return false;
    }

    /**
     * Get the name of the settings file to be installed on new context
     * creation.
     *
     * @return string
     */
    public function getContextSpecificPluginSettingsFile()
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * Inject Dublin Core metadata into article view
     *
     * @param string $hookName
     * @param array $args
     *
     * @return bool
     */
    public function articleView($hookName, $args)
    {
        $request = $args[0];
        $issue = $args[1];
        $article = $args[2];
        $publication = $article->getCurrentPublication();
        $requestArgs = $request->getRequestedArgs();
        $journal = $request->getContext();

        // Only add Dublin Core metadata tags to the canonical URL for the latest version
        // See discussion: https://github.com/pkp/pkp-lib/issues/4870
        if (count($requestArgs) > 1 && $requestArgs[1] === 'version') {
            return;
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addHeader('dublinCoreSchema', '<link rel="schema.DC" href="http://purl.org/dc/elements/1.1/" />');

        $i = 0;
        if ($sponsors = $article->getSponsor(null)) {
            foreach ($sponsors as $locale => $sponsor) {
                $templateMgr->addHeader('dublinCoreSponsor' . $i++, '<meta name="DC.Contributor.Sponsor" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars(strip_tags($sponsor)) . '"/>');
            }
        }

        $i = 0;
        if ($coverages = $article->getCoverage(null)) {
            foreach ($coverages as $locale => $coverage) {
                $templateMgr->addHeader('dublinCoreCoverage' . $i++, '<meta name="DC.Coverage" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars(strip_tags($coverage)) . '"/>');
            }
        }

        $i = 0;
        $authors = Repo::author()->getSubmissionAuthors($article, true);
        foreach ($authors as $author) {
            $templateMgr->addHeader('dublinCoreAuthor' . $i++, '<meta name="DC.Creator.PersonalName" content="' . htmlspecialchars($author->getFullName(false)) . '"/>');
        }

        if ($article instanceof Submission && ($datePublished = $article->getDatePublished())) {
            $templateMgr->addHeader('dublinCoreDateCreated', '<meta name="DC.Date.created" scheme="ISO8601" content="' . date('Y-m-d', strtotime($datePublished)) . '"/>');
        }
        $templateMgr->addHeader('dublinCoreDateSubmitted', '<meta name="DC.Date.dateSubmitted" scheme="ISO8601" content="' . date('Y-m-d', strtotime($article->getDateSubmitted())) . '"/>');
        if ($issue && ($datePublished = $issue->getDatePublished())) {
            $templateMgr->addHeader('dublinCoreDateIssued', '<meta name="DC.Date.issued" scheme="ISO8601" content="' . date('Y-m-d', strtotime($issue->getDatePublished())) . '"/>');
        }
        if ($dateModified = $article->getCurrentPublication()->getData('lastModified')) {
            $templateMgr->addHeader('dublinCoreDateModified', '<meta name="DC.Date.modified" scheme="ISO8601" content="' . date('Y-m-d', strtotime($dateModified)) . '"/>');
        }

        if ($publication) {
            $i = 0;
            if ($abstracts = (array) $publication->getData('abstract')) {
                foreach ($abstracts as $locale => $abstract) {
                    $templateMgr->addHeader('dublinCoreAbstract' . $i++, '<meta name="DC.Description" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars(strip_tags($abstract)) . '"/>');
                }
            }
        }

        $i = 0;
        if ($article instanceof Submission) {
            foreach ($article->getGalleys() as $galley) {
                $templateMgr->addHeader('dublinCoreFormat' . $i++, '<meta name="DC.Format" scheme="IMT" content="' . htmlspecialchars($galley->getFileType()) . '"/>');
            }
        }

        $templateMgr->addHeader('dublinCoreIdentifier', '<meta name="DC.Identifier" content="' . htmlspecialchars($article->getBestId()) . '"/>');

        if ($pages = $article->getPages()) {
            $templateMgr->addHeader('dublinCorePages', '<meta name="DC.Identifier.pageNumber" content="' . htmlspecialchars($pages) . '"/>');
        }

        foreach ((array) $templateMgr->getTemplateVars('pubIdPlugins') as $pubIdPlugin) {
            if ($pubId = $article->getStoredPubId($pubIdPlugin->getPubIdType())) {
                $templateMgr->addHeader('dublinCorePubId' . $pubIdPlugin->getPubIdDisplayType(), '<meta name="DC.Identifier.' . htmlspecialchars($pubIdPlugin->getPubIdDisplayType()) . '" content="' . htmlspecialchars($pubId) . '"/>');
            }
        }

        $templateMgr->addHeader('dublinCoreUri', '<meta name="DC.Identifier.URI" content="' . $request->url(null, 'article', 'view', [$article->getBestId()]) . '"/>');
        $templateMgr->addHeader('dublinCoreLanguage', '<meta name="DC.Language" scheme="ISO639-1" content="' . substr($article->getLocale(), 0, 2) . '"/>');
        $templateMgr->addHeader('dublinCoreCopyright', '<meta name="DC.Rights" content="' . htmlspecialchars(__('submission.copyrightStatement', ['copyrightHolder' => $article->getCopyrightHolder($article->getLocale()), 'copyrightYear' => $article->getCopyrightYear()])) . '"/>');
        $templateMgr->addHeader('dublinCorePagesLicenseUrl', '<meta name="DC.Rights" content="' . htmlspecialchars($article->getLicenseURL()) . '"/>');
        $templateMgr->addHeader('dublinCoreSource', '<meta name="DC.Source" content="' . htmlspecialchars($journal->getName($journal->getPrimaryLocale())) . '"/>');
        if (($issn = $journal->getData('onlineIssn')) || ($issn = $journal->getData('printIssn')) || ($issn = $journal->getData('issn'))) {
            $templateMgr->addHeader('dublinCoreIssn', '<meta name="DC.Source.ISSN" content="' . htmlspecialchars($issn) . '"/>');
        }

        if ($issue) {
            if ($issue->getShowNumber()) {
                $templateMgr->addHeader('dublinCoreIssue', '<meta name="DC.Source.Issue" content="' . htmlspecialchars($issue->getNumber()) . '"/>');
            }
            if ($issue->getShowVolume()) {
                $templateMgr->addHeader('dublinCoreVolume', '<meta name="DC.Source.Volume" content="' . htmlspecialchars($issue->getVolume()) . '"/>');
            }
        }

        $templateMgr->addHeader('dublinCoreSourceUri', '<meta name="DC.Source.URI" content="' . $request->url($journal->getPath()) . '"/>');

        $dao = DAORegistry::getDAO('SubmissionKeywordDAO');
        $keywords = $dao->getKeywords($article->getCurrentPublication()->getId(), [Locale::getLocale()]);
        foreach ($keywords as $locale => $localeKeywords) {
            foreach ($localeKeywords as $i => $keyword) {
                $templateMgr->addHeader('dublinCoreSubject' . $locale . $i, '<meta name="DC.Subject" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars($keyword) . '"/>');
            }
        }

        if ($publication) {
            $templateMgr->addHeader('dublinCoreTitle', '<meta name="DC.Title" content="' . htmlspecialchars($publication->getLocalizedFullTitle($publication->getData('locale'))) . '"/>');
            foreach ($publication->getFullTitles() as $locale => $title) {
                if (empty($title) || $locale === $publication->getData('locale')) {
                    continue;
                }
                $templateMgr->addHeader('dublinCoreAltTitle' . $locale, '<meta name="DC.Title.Alternative" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars($title) . '"/>');
            }
        }

        $templateMgr->addHeader('dublinCoreType', '<meta name="DC.Type" content="Text.Serial.Journal"/>');
        if ($types = $article->getType(null)) {
            foreach ($types as $locale => $type) {
                $templateMgr->addHeader('dublinCoreType' . $locale, '<meta name="DC.Type" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars(strip_tags($type)) . '"/>');
            }
        }

        if ($publication) {
            $sectionDao = DAORegistry::getDAO('SectionDAO'); /** @var SectionDAO $sectionDao */
            $section = $sectionDao->getById($publication->getData('sectionId'));
            $templateMgr->addHeader('dublinCoreArticleType', '<meta name="DC.Type.articleType" content="' . htmlspecialchars($section->getTitle($journal->getPrimaryLocale())) . '"/>');
        }

        return false;
    }

    /**
     * Get the display name of this plugin
     *
     * @return string
     */
    public function getDisplayName()
    {
        return __('plugins.generic.dublinCoreMeta.name');
    }

    /**
     * Get the description of this plugin
     *
     * @return string
     */
    public function getDescription()
    {
        return __('plugins.generic.dublinCoreMeta.description');
    }
}
