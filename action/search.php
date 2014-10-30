<?php
/**
 * DokuWiki Plugin elasticsearch (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class action_plugin_elasticsearch_search extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler &$controller) {

        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_preprocess');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'handle_action');

    }

    /**
     * allow our custom do command
     *
     * @param Doku_Event $event
     * @param $param
     */
    public function handle_preprocess(Doku_Event &$event, $param) {
        if($event->data != 'elasticsearch') return;
        $event->preventDefault();
        $event->stopPropagation();
    }

    /**
     * do the actual search
     *
     * @param Doku_Event $event
     * @param $param
     */
    public function handle_action(Doku_Event &$event, $param) {
        if($event->data != 'elasticsearch') return;
        $event->preventDefault();
        $event->stopPropagation();
        global $QUERY;
        global $INPUT;
        global $USERINFO;

        /** @var helper_plugin_elasticsearch_client $hlp */
        $hlp = plugin_load('helper', 'elasticsearch_client');

        $client = $hlp->connect();
        $index  = $client->getIndex($this->getConf('indexname'));

        // define the query string
        $qstring = new \Elastica\Query\SimpleQueryString($QUERY);

        // create the actual search object
        $equery = new \Elastica\Query();
        $equery->setQuery($qstring);
        $equery->setHighlight(
            array(
                "pre_tags"  => array('ELASTICSEARCH_MARKER_IN'),
                "post_tags" => array('ELASTICSEARCH_MARKER_OUT'),
                "fields"    => array("content" => new \StdClass())
            )
        );

        // paginate
        $equery->setSize($this->getConf('perpage'));
        $equery->setFrom($this->getConf('perpage') * ($INPUT->int('p', 1, true) - 1));

        $filters = new \Elastica\Filter\BoolAnd();

        // add groupfilter
        $groups = array('all');
        if(isset($USERINFO['grps'])) {
            $groups = array_merge($groups, $USERINFO['grps']);
        }
        $groupFilter = new \Elastica\Filter\BoolOr();
        foreach($groups as $group) {
            $group  = str_replace('-', '', strtolower($group));
            $filter = new \Elastica\Filter\Term();
            $filter->setTerm('groups', $group);
            $groupFilter->addFilter($filter);
        }
        $filters->addFilter($groupFilter);

        // add namespace filter
        if($INPUT->has('ns')) {
            $facetFilter = new \Elastica\Filter\BoolOr();
            foreach($INPUT->arr('ns') as $term) {
                $filter = new \Elastica\Filter\Term();
                $filter->setTerm('namespace', $term);
                $facetFilter->addFilter($filter);
            }
            $filters->addFilter($facetFilter);
        }

        // set all filters
        $equery->setFilter($filters);

        // add Facets for namespaces
        $facet = new \Elastica\Facet\Terms('namespace');
        $facet->setField('namespace');
        $facet->setSize(25);
        $equery->addFacet($facet);

        try {
            $result = $index->search($equery);
            $facets = $result->getFacets();

            $this->print_intro();
            $this->print_facets($facets['namespace']['terms']);
            $this->print_results($result) && $this->print_pagination($result);
        } catch(Exception $e) {
            msg('Something went wrong on searching please try again later or ask an admin for help.<br /><pre>' . hsc($e->getMessage()) . '</pre>', -1);
        }
    }

    /**
     * Prints the introduction text
     */
    protected function print_intro() {
        global $QUERY;
        global $ID;
        global $lang;

        // just reuse the standard search page intro:
        $intro = p_locale_xhtml('searchpage');
        // allow use of placeholder in search intro
        $pagecreateinfo = (auth_quickaclcheck($ID) >= AUTH_CREATE) ? $lang['searchcreatepage'] : '';
        $intro          = str_replace(
            array('@QUERY@', '@SEARCH@', '@CREATEPAGEINFO@'),
            array(hsc(rawurlencode($QUERY)), hsc($QUERY), $pagecreateinfo),
            $intro
        );
        echo $intro;
        flush();
    }

    /**
     * Output the search results
     *
     * @param \Elastica\Result[] $results
     * @return bool true when results where shown
     */
    protected function print_results($results) {
        global $lang;

        // output results
        $found = 0;
        echo '<dl class="search_results">';
        foreach($results as $row) {
            /** @var Elastica\Result $row */

            $page = $row->getSource()['uri'];
            if(!page_exists($page) || auth_quickaclcheck($page) < AUTH_READ) continue;

            echo '<dt>';
            echo html_wikilink($page);
            echo '<div>';
            if($row->getSource()['namespace']) {
                echo '<span class="ns"><b>' . $this->getLang('ns') . '</b> ' . hsc($row->getSource()['namespace']) . '</span><br />';
            }
            if($row->getSource()['creator']) {
                echo '<span class="author"><b>' . $this->getLang('author') . '</b> ' . hsc($row->getSource()['creator']) . '</span>';
            }
            echo '</div>';
            echo '</dt>';

            // snippets
            echo '<dd>';
            echo str_replace(
                array('ELASTICSEARCH_MARKER_IN', 'ELASTICSEARCH_MARKER_OUT'),
                array('<strong class="search_hit">', '</strong>'),
                hsc(join(' … ', (array) $row->getHighlights()['content']))
            );
            echo '</dd>';
            $found++;
        }
        if(!$found) {
            echo '<dt>' . $lang['nothingfound'] . '</dt>';
        }
        echo '</dl>';

        return (bool) $found;
    }

    /**
     * Output the namespace facets
     *
     * @param array $facets Facet terms
     */
    protected function print_facets($facets) {
        global $INPUT;
        global $QUERY;
        global $lang;

        echo '<form action="' . wl() . '" class="elastic_facets">';
        echo '<legend>' . $this->getLang('ns') . '</legend>';
        echo '<input name="id" type="hidden" value="' . formText($QUERY) . '" />';
        echo '<input name="do" type="hidden" value="elasticsearch" />';
        echo '<ul>';
        foreach($facets as $facet) {

            echo '<li><div class="li">';
            if(in_array($facet['term'], $INPUT->arr('ns'))) {
                $on = ' checked="checked"';
            } else {
                $on = '';
            }

            echo '<label><input name="ns[]" type="checkbox"' . $on . ' value="' . formText($facet['term']) . '" /> ' . hsc($facet['term']) . '</label>';
            echo '</div></li>';
        }
        echo '</ul>';
        echo '<input type="submit" value="' . $lang['btn_search'] . '" class="button" />';
        echo '</form>';
    }

    /**
     * @param \Elastica\ResultSet $result
     */
    protected function print_pagination($result) {
        global $INPUT;
        global $QUERY;

        $all   = $result->getTotalHits();
        $pages = ceil($all / $this->getConf('perpage'));
        $cur   = $INPUT->int('p', 1, true);

        if($pages < 2) return;

        // which pages to show
        $toshow = array(1, 2, $cur, $pages, $pages - 1);
        if($cur - 1 > 1) $toshow[] = $cur - 1;
        if($cur + 1 < $pages) $toshow[] = $cur + 1;
        $toshow = array_unique($toshow);
        // fill up to seven, if possible
        if(count($toshow) < 7) {
            if($cur < 4) {
                if($cur + 2 < $pages && count($toshow) < 7) $toshow[] = $cur + 2;
                if($cur + 3 < $pages && count($toshow) < 7) $toshow[] = $cur + 3;
                if($cur + 4 < $pages && count($toshow) < 7) $toshow[] = $cur + 4;
            } else {
                if($cur - 2 > 1 && count($toshow) < 7) $toshow[] = $cur - 2;
                if($cur - 3 > 1 && count($toshow) < 7) $toshow[] = $cur - 3;
                if($cur - 4 > 1 && count($toshow) < 7) $toshow[] = $cur - 4;
            }
        }
        sort($toshow);
        $showlen = count($toshow);

        echo '<ul class="elastic_pagination">';
        if($cur > 1) {
            echo '<li class="prev">';
            echo '<a href="' . wl('', http_build_query(array('id' => $QUERY, 'do' => 'elasticsearch', 'ns' => $INPUT->arr('ns'), 'p' => ($cur-1)))) . '">';
            echo '«';
            echo '</a>';
            echo '</li>';
        }

        for($i = 0; $i < $showlen; $i++) {
            if($toshow[$i] == $cur) {
                echo '<li class="cur">' . $toshow[$i] . '</li>';
            } else {
                echo '<li>';
                echo '<a href="' . wl('', http_build_query(array('id' => $QUERY, 'do' => 'elasticsearch', 'ns' => $INPUT->arr('ns'), 'p' => $toshow[$i]))) . '">';
                echo $toshow[$i];
                echo '</a>';
                echo '</li>';
            }

            // show seperator when a jump follows
            if(isset($toshow[$i + 1]) && $toshow[$i + 1] - $toshow[$i] > 1) {
                echo '<li class="sep">…</li>';
            }
        }

        if($cur < $pages) {
            echo '<li class="next">';
            echo '<a href="' . wl('', http_build_query(array('id' => $QUERY, 'do' => 'elasticsearch', 'ns' => $INPUT->arr('ns'), 'p' => ($cur+1)))) . '">';
            echo '»';
            echo '</a>';
            echo '</li>';
        }

        echo '</ul>';
    }

}