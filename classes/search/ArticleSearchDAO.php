<?php

/**
 * @file classes/search/ArticleSearchDAO.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleSearchDAO
 * @ingroup search
 *
 * @see ArticleSearch
 *
 * @brief DAO class for article search index.
 */

namespace APP\search;

use PKP\search\SubmissionSearchDAO;
use PKP\submission\PKPSubmission;

class ArticleSearchDAO extends SubmissionSearchDAO
{
    /**
     * Retrieve the top results for a phrase.
     *
     * @param Journal $journal
     * @param array $phrase
     * @param int|null $publishedFrom Optional start date
     * @param int|null $publishedTo Optional end date
     * @param int|null $type ASSOC_TYPE_...
     * @param int $limit
     *
     * @return array of results (associative arrays)
     */
    public function getPhraseResults($journal, $phrase, $publishedFrom = null, $publishedTo = null, $type = null, $limit = 500)
    {
        if (empty($phrase)) {
            return [];
        }

        $sqlFrom = '';
        $sqlWhere = '';
        $params = [];

        for ($i = 0, $count = count($phrase); $i < $count; $i++) {
            if (!empty($sqlFrom)) {
                $sqlFrom .= ', ';
                $sqlWhere .= ' AND ';
            }
            $sqlFrom .= 'submission_search_object_keywords o' . $i . ' NATURAL JOIN submission_search_keyword_list k' . $i;
            if (strstr($phrase[$i], '%') === false) {
                $sqlWhere .= 'k' . $i . '.keyword_text = ?';
            } else {
                $sqlWhere .= 'k' . $i . '.keyword_text LIKE ?';
            }
            if ($i > 0) {
                $sqlWhere .= ' AND o0.object_id = o' . $i . '.object_id AND o0.pos+' . $i . ' = o' . $i . '.pos';
            }

            $params[] = $phrase[$i];
        }

        if (!empty($type)) {
            $sqlWhere .= ' AND (o.type & ?) != 0';
            $params[] = $type;
        }

        if (!empty($publishedFrom)) {
            $sqlWhere .= ' AND p.date_published >= ' . $this->datetimeToDB($publishedFrom);
        }

        if (!empty($publishedTo)) {
            $sqlWhere .= ' AND p.date_published <= ' . $this->datetimeToDB($publishedTo);
        }

        if (!empty($journal)) {
            $sqlWhere .= ' AND i.journal_id = ?';
            $params[] = $journal->getId();
        }

        $result = $this->retrieve(
            'SELECT
				o.submission_id,
				MAX(s.context_id) AS journal_id,
				MAX(i.date_published) AS i_pub,
				MAX(p.date_published) AS s_pub,
				COUNT(*) AS count
			FROM
				submissions s
				JOIN publications p ON (p.publication_id = s.current_publication_id)
				JOIN publication_settings ps ON (ps.publication_id = p.publication_id AND ps.setting_name=\'issueId\' AND ps.locale=\'\')
				JOIN issues i ON (CAST(i.issue_id AS CHAR(20)) = ps.setting_value AND i.journal_id = s.context_id)
				JOIN submission_search_objects o ON (s.submission_id = o.submission_id)
				NATURAL JOIN ' . $sqlFrom . '
			WHERE
				s.status = ' . PKPSubmission::STATUS_PUBLISHED . ' AND
				i.published = 1 AND ' . $sqlWhere . '
			GROUP BY o.submission_id
			ORDER BY count DESC
			LIMIT ' . $limit,
            $params
        );

        $returner = [];
        foreach ($result as $row) {
            $returner[$row->submission_id] = [
                'count' => $row->count,
                'journal_id' => $row->journal_id,
                'issuePublicationDate' => $this->datetimeFromDB($row->i_pub),
                'publicationDate' => $this->datetimeFromDB($row->s_pub)
            ];
        }
        return $returner;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\search\ArticleSearchDAO', '\ArticleSearchDAO');
}
