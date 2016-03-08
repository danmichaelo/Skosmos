<?php
/**
 * Copyright (c) 2012-2013 Aalto University and University of Helsinki
 * MIT License
 * see LICENSE.txt for more information
 */

/**
 * Rest controller is an extension of the controller so that must be imported.
 */
require_once 'controller/Controller.php';

/**
 * RestController is responsible for handling all the requests directed to the /rest address.
 */
class RestController extends Controller
{
    /* supported MIME types that can be used to return RDF data */
    private static $supportedMIMETypes = 'application/rdf+xml text/turtle application/ld+json application/json';
    /* context array template */
    private $context = array(
        '@context' => array(
            'skos' => 'http://www.w3.org/2004/02/skos/core#',
            'uri' => '@id',
            'type' => '@type',
        ),
    );

    /**
     * Echos an error message when the request can't be fulfilled.
     * @param string $code
     * @param string $status
     * @param string $message
     */
    private function returnError($code, $status, $message)
    {
        header("HTTP/1.0 $code $status");
        header("Content-type: text/plain; charset=utf-8");
        echo "$code $status : $message";
    }

    /**
     * Handles json encoding, adding the content type headers and optional callback function.
     * @param array $data the data to be returned.
     */
    private function returnJson($data)
    {
        // wrap with JSONP callback if requested
        if (filter_input(INPUT_GET, 'callback', FILTER_SANITIZE_STRING)) {
            header("Content-type: application/javascript; charset=utf-8");
            echo filter_input(INPUT_GET, 'callback', FILTER_UNSAFE_RAW) . "(" . json_encode($data) . ");";
            return;
        }
        
        // otherwise negotiate suitable format for the response and return that
        $negotiator = new \Negotiation\FormatNegotiator();
        $priorities = array('application/json', 'application/ld+json');
        $best = filter_input(INPUT_SERVER, 'HTTP_ACCEPT', FILTER_SANITIZE_STRING) ? $negotiator->getBest(filter_input(INPUT_SERVER, 'HTTP_ACCEPT', FILTER_SANITIZE_STRING), $priorities) : null;
        $format = ($best !== null) ? $best->getValue() : $priorities[0];
        header("Content-type: $format; charset=utf-8");
        header("Vary: Accept"); // inform caches that we made a choice based on Accept header
        echo json_encode($data);
    }

    /**
     * Parses and returns the limit parameter. Returns and error if the parameter is missing.
     */
    private function parseLimit()
    {
        $limit = filter_input(INPUT_GET, 'limit', FILTER_SANITIZE_NUMBER_INT) ? filter_input(INPUT_GET, 'limit', FILTER_SANITIZE_NUMBER_INT) : $this->model->getConfig()->getDefaultTransitiveLimit();
        if ($limit <= 0) {
            return $this->returnError(400, "Bad Request", "Invalid limit parameter");
        }

        return $limit;
    }

    /**
     * Negotiate a MIME type according to the proposed format, the list of valid
     * formats, and an optional proposed format.
     * As a side effect, set the HTTP Vary header if a choice was made based on
     * the Accept header.
     * @param array $choices possible MIME types as strings
     * @param string $accept HTTP Accept header value
     * @param string $format proposed format
     * @return string selected format, or null if negotiation failed
     */
    private function negotiateFormat($choices, $accept, $format)
    {
        if ($format) {
            if (!in_array($format, $choices)) {
                return null;
            }
            return $format;
        }
        
        // if there was no proposed format, negotiate a suitable format
        header('Vary: Accept'); // inform caches that a decision was made based on Accept header
        $best = $this->negotiator->getBest($accept, $choices);
        $format = ($best !== null) ? $best->getValue() : null;
        return $format;
    }

/** Global REST methods **/

    /**
     * Returns all the vocabularies available on the server in a json object.
     */
    public function vocabularies($request)
    {
        if (!$request->getLang()) {
            return $this->returnError(400, "Bad Request", "lang parameter missing");
        }

        $this->setLanguageProperties($request->getLang());

        $vocabs = array();
        foreach ($this->model->getVocabularies() as $voc) {
            $vocabs[$voc->getId()] = $voc->getConfig()->getTitle($request->getLang());
        }
        ksort($vocabs);
        $results = array();
        foreach ($vocabs as $id => $title) {
            $results[] = array(
                'uri' => $id,
                'id' => $id,
                'title' => $title);
        }

        /* encode the results in a JSON-LD compatible array */
        $ret = array(
            '@context' => array(
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'onki' => 'http://schema.onki.fi/onki#',
                'title' => array('@id' => 'rdfs:label', '@language' => $request->getLang()),
                'vocabularies' => 'onki:hasVocabulary',
                'id' => 'onki:vocabularyIdentifier',
                'uri' => '@id',
            ),
            'uri' => '',
            'vocabularies' => $results,
        );

        return $this->returnJson($ret);
    }

    /**
     * Performs the search function calls. And wraps the result in a json-ld object.
     * @param Request $request
     */
    public function search($request)
    {
        $maxhits = $request->getQueryParam('maxhits');
        $offset = $request->getQueryParam('offset');
        $term = $request->getQueryParam('query');

        if (!$term) {
            return $this->returnError(400, "Bad Request", "query parameter missing");
        }
        if ($maxhits && (!is_numeric($maxhits) || $maxhits <= 0)) {
            return $this->returnError(400, "Bad Request", "maxhits parameter is invalid");
        }
        if ($offset && (!is_numeric($offset) || $offset < 0)) {
            return $this->returnError(400, "Bad Request", "offset parameter is invalid");
        }

        $parameters = new ConceptSearchParameters($request, $this->model->getConfig(), true);
        
        $vocabs = $request->getQueryParam('vocab'); # optional
        // convert to vocids array to support multi-vocabulary search
        $vocids = ($vocabs !== null && $vocabs !== '') ? explode(' ', $vocabs) : array();
        $vocabObjects = array();
        foreach($vocids as $vocid) {
            $vocabObjects[] = $this->model->getVocabulary($vocid);
        }
        $parameters->setVocabularies($vocabObjects);

        $results = $this->model->searchConcepts($parameters);
        // before serializing to JSON, get rid of the Vocabulary object that came with each resource
        foreach ($results as &$res) {
            unset($res['voc']);
        }

        $ret = array(
            '@context' => array(
                'skos' => 'http://www.w3.org/2004/02/skos/core#',
                'onki' => 'http://schema.onki.fi/onki#',
                'uri' => '@id',
                'type' => '@type',
                'results' => array(
                    '@id' => 'onki:results',
                    '@container' => '@list',
                ),
                'prefLabel' => 'skos:prefLabel',
                'altLabel' => 'skos:altLabel',
                'hiddenLabel' => 'skos:hiddenLabel',
                'broader' => 'skos:broader',
            ),
            'uri' => '',
            'results' => $results,
        );

        if ($request->getQueryParam('labellang')) {
            $ret['@context']['@language'] = $request->getQueryParam('labellang');
        } elseif ($request->getQueryParam('lang')) {
            $ret['@context']['@language'] = $request->getQueryParam('lang');;
        }

        return $this->returnJson($ret);
    }

/** Vocabulary-specific methods **/

    /**
     * Loads the vocabulary metadata. And wraps the result in a json-ld object.
     * @param Request $request
     */
    public function vocabularyInformation($request)
    {
        $vocab = $request->getVocab();

        /* encode the results in a JSON-LD compatible array */
        $conceptschemes = array();
        foreach ($vocab->getConceptSchemes($request->getLang()) as $uri => $csdata) {
            $csdata['uri'] = $uri;
            $csdata['type'] = 'skos:ConceptScheme';
            $conceptschemes[] = $csdata;
        }

        $ret = array(
            '@context' => array(
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'skos' => 'http://www.w3.org/2004/02/skos/core#',
                'onki' => 'http://schema.onki.fi/onki#',
                'dct' => 'http://purl.org/dc/terms/',
                'uri' => '@id',
                'type' => '@type',
                'title' => 'rdfs:label',
                'conceptschemes' => 'onki:hasConceptScheme',
                'id' => 'onki:vocabularyIdentifier',
                'defaultLanguage' => 'onki:defaultLanguage',
                'languages' => 'onki:language',
                'label' => 'rdfs:label',
                'prefLabel' => 'skos:prefLabel',
                'title' => 'dct:title',
                '@language' => $request->getLang(),
            ),
            'uri' => '',
            'id' => $vocab->getId(),
            'title' => $vocab->getConfig()->getTitle($request->getLang()),
            'defaultLanguage' => $vocab->getConfig()->getDefaultLanguage(),
            'languages' => $vocab->getConfig()->getLanguages(),
            'conceptschemes' => $conceptschemes,
        );

        return $this->returnJson($ret);
    }

    /**
     * Loads the vocabulary metadata. And wraps the result in a json-ld object.
     * @param Request $request
     */
    public function vocabularyStatistics($request)
    {
        $this->setLanguageProperties($request->getLang());
        $arrayClass = $request->getVocab()->getConfig()->getArrayClassURI(); 
        $groupClass = $request->getVocab()->getConfig()->getGroupClassURI(); 
        $vocabStats = $request->getVocab()->getStatistics($request->getQueryParam('lang'), $arrayClass, $groupClass);
        $types = array('http://www.w3.org/2004/02/skos/core#Concept', 'http://www.w3.org/2004/02/skos/core#Collection', $arrayClass, $groupClass);
        $subTypes = array();
        foreach ($vocabStats as $subtype) {
            if (!in_array($subtype['type'], $types)) {
                $subTypes[] = $subtype;
            }
        }

        /* encode the results in a JSON-LD compatible array */
        $ret = array(
            '@context' => array(
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'skos' => 'http://www.w3.org/2004/02/skos/core#',
                'void' => 'http://rdfs.org/ns/void#',
                'onki' => 'http://schema.onki.fi/onki#',
                'uri' => '@id',
                'id' => 'onki:vocabularyIdentifier',
                'concepts' => 'void:classPartition',
                'label' => 'rdfs:label',
                'class' => array('@id' => 'void:class', '@type' => '@id'),
                'subTypes' => array('@id' => 'void:class', '@type' => '@id'),
                'count' => 'void:entities',
                '@language' => $request->getLang(),
            ),
            'uri' => '',
            'id' => $request->getVocab()->getId(),
            'title' => $request->getVocab()->getConfig()->getTitle(),
            'concepts' => array(
                'class' => 'http://www.w3.org/2004/02/skos/core#Concept',
                'label' => gettext('skos:Concept'),
                'count' => $vocabStats['http://www.w3.org/2004/02/skos/core#Concept']['count'],
            ),
            'subTypes' => $subTypes,
        );

        if (isset($vocabStats['http://www.w3.org/2004/02/skos/core#Collection'])) {
            $ret['conceptGroups'] = array(
                'class' => 'http://www.w3.org/2004/02/skos/core#Collection',
                'label' => gettext('skos:Collection'),
                'count' => $vocabStats['http://www.w3.org/2004/02/skos/core#Collection']['count'],
            );
        } else if (isset($vocabStats[$groupClass])) {
            $ret['conceptGroups'] = array(
                'class' => $groupClass,
                'label' => isset($vocabStats[$groupClass]['label']) ? $vocabStats[$groupClass]['label'] : gettext(EasyRdf_Namespace::shorten($groupClass)),
                'count' => $vocabStats[$groupClass]['count'],
            );
        } else if (isset($vocabStats[$arrayClass])) {
            $ret['arrays'] = array(
                'class' => $arrayClass,
                'label' => isset($vocabStats[$arrayClass]['label']) ? $vocabStats[$arrayClass]['label'] : gettext(EasyRdf_Namespace::shorten($arrayClass)),
                'count' => $vocabStats[$arrayClass]['count'],
            );
        }

        return $this->returnJson($ret);
    }

    /**
     * Loads the vocabulary metadata. And wraps the result in a json-ld object.
     * @param Request $request
     */
    public function labelStatistics($request)
    {
        $lang = $request->getLang();
        $this->setLanguageProperties($request->getLang());
        $vocabStats = $request->getVocab()->getLabelStatistics();

        /* encode the results in a JSON-LD compatible array */
        $counts = array();
        foreach ($vocabStats['terms'] as $proplang => $properties) {
            $langdata = array('language' => $proplang);
            if ($lang) {
                $langdata['literal'] = Punic\Language::getName($proplang, $lang);
            }

            $langdata['properties'] = array();
            foreach ($properties as $prop => $value) {
                $langdata['properties'][] = array('property' => $prop, 'labels' => $value);
            }
            $counts[] = $langdata;
        }

        $ret = array(
            '@context' => array(
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'skos' => 'http://www.w3.org/2004/02/skos/core#',
                'void' => 'http://rdfs.org/ns/void#',
                'void-ext' => 'http://ldf.fi/void-ext#',
                'onki' => 'http://schema.onki.fi/onki#',
                'uri' => '@id',
                'id' => 'onki:vocabularyIdentifier',
                'languages' => 'void-ext:languagePartition',
                'language' => 'void-ext:language',
                'properties' => 'void:propertyPartition',
                'labels' => 'void:triples',
            ),
            'uri' => '',
            'id' => $request->getVocab()->getId(),
            'title' => $request->getVocab()->getConfig()->getTitle($lang),
            'languages' => $counts,
        );

        if ($lang) {
            $ret['@context']['literal'] = array('@id' => 'rdfs:label', '@language' => $lang);
        }

        return $this->returnJson($ret);
    }

    /**
     * Loads the vocabulary type metadata. And wraps the result in a json-ld object.
     * @param Request $request
     */
    public function types($request)
    {
        $vocid = $request->getVocab() ? $request->getVocab()->getId() : null;
        if ($vocid === null && !$request->getLang()) {
            return $this->returnError(400, "Bad Request", "lang parameter missing");
        }
        $this->setLanguageProperties($request->getLang());
        
        $queriedtypes = $this->model->getTypes($vocid, $request->getLang());

        $types = array();

        /* encode the results in a JSON-LD compatible array */
        foreach ($queriedtypes as $uri => $typedata) {
            $type = array_merge(array('uri' => $uri), $typedata);
            $types[] = $type;
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('rdfs' => 'http://www.w3.org/2000/01/rdf-schema#', 'onki' => 'http://schema.onki.fi/onki#', 'label' => 'rdfs:label', 'superclass' => array('@id' => 'rdfs:subClassOf', '@type' => '@id'), 'types' => 'onki:hasType', '@language' => $request->getLang()),
            'uri' => '',
            'types' => $types)
        );

        return $this->returnJson($ret);
    }

    /**
     * Used for finding terms by their exact prefLabel. Wraps the result in a json-ld object.
     * @param Request $request
     */
    public function lookup($request)
    {
        $label = $request->getQueryParam('label');
        if (!$label) {
            return $this->returnError(400, "Bad Request", "label parameter missing");
        }

        $lang = $request->getQueryParam('lang');

        $parameters = new ConceptSearchParameters($request, $this->model->getConfig(), true);

        $results = $this->model->searchConcepts($parameters);

        $hits = array();
        // case 1: exact match on preferred label
        foreach ($results as $res) {
            if ($res['prefLabel'] == $label) {
                $hits[] = $res;
            }
        }

        // case 2: case-insensitive match on preferred label
        if (sizeof($hits) == 0) { // not yet found
            foreach ($results as $res) {
                if (strtolower($res['prefLabel']) == strtolower($label)) {
                    $hits[] = $res;
                }
            }

        }

        // case 3: exact match on alternate label
        if (sizeof($hits) == 0) { // not yet found
            foreach ($results as $res) {
                if (isset($res['altLabel']) && $res['altLabel'] == $label) {
                    $hits[] = $res;
                }
            }

        }

        // case 4: case-insensitive match on alternate label
        if (sizeof($hits) == 0) { // not yet found
            foreach ($results as $res) {
                if (isset($res['altLabel']) && strtolower($res['altLabel']) == strtolower($label)) {
                    $hits[] = $res;
                }
            }

        }

        if (sizeof($hits) == 0) {
            // no matches found
            return $this->returnError(404, 'Not Found', "Could not find label '$label'");
        }

        // did find some matches!
        // get rid of Vocabulary objects
        foreach ($hits as &$res) {
            unset($res['voc']);
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('onki' => 'http://schema.onki.fi/onki#', 'results' => array('@id' => 'onki:results'), 'prefLabel' => 'skos:prefLabel', 'altLabel' => 'skos:altLabel', 'hiddenLabel' => 'skos:hiddenLabel'),
            'result' => $hits)
        );

        if ($lang) {
            $ret['@context']['@language'] = $lang;
        }

        return $this->returnJson($ret);
    }

    /**
     * Queries the top concepts of a vocabulary and wraps the results in a json-ld object.
     * @param Request $request
     * @return object json-ld object
     */
    public function topConcepts($request)
    {
        $vocab = $request->getVocab();
        $scheme = $request->getQueryParam('scheme');
        if (!$scheme) {
            $scheme = $vocab->getConfig()->showConceptSchemesInHierarchy() ? array_keys($vocab->getConceptSchemes()) : $vocab->getDefaultConceptScheme();
        }

        /* encode the results in a JSON-LD compatible array */
        $topconcepts = $vocab->getTopConcepts($scheme, $request->getLang());

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('onki' => 'http://schema.onki.fi/onki#', 'topconcepts' => 'skos:hasTopConcept', 'notation' => 'skos:notation', 'label' => 'skos:prefLabel', '@language' => $request->getLang()),
            'uri' => $scheme,
            'topconcepts' => $topconcepts)
        );

        return $this->returnJson($ret);
    }

    /**
     * Download a concept as json-ld or redirect to download the whole vocabulary.
     * @param Request $request
     * @return object json-ld formatted concept.
     */
    public function data($request)
    {
        $vocab = $request->getVocab();
        $format = $request->getQueryParam('format');

        if ($request->getUri()) {
            $uri = $request->getUri();
        } else if ($vocab !== null) { // whole vocabulary - redirect to download URL
            $urls = $vocab->getConfig()->getDataURLs();
            if (sizeof($urls) == 0) {
                return $this->returnError('404', 'Not Found', "No download source URL known for vocabulary $vocab");
            }

            $format = $this->negotiateFormat(array_keys($urls), $request->getServerConstant('HTTP_ACCEPT'), $format);
            if (!$format) {
                return $this->returnError(406, 'Not Acceptable', "Unsupported format. Supported MIME types are: " . implode(' ', array_keys($urls)));
            }

            header("Location: " . $urls[$format]);
            return;
        }

        $format = $this->negotiateFormat(explode(' ', self::$supportedMIMETypes), $request->getServerConstant('HTTP_ACCEPT'), $format);
        if (!$format) {
            return $this->returnError(406, 'Not Acceptable', "Unsupported format. Supported MIME types are: " . self::$supportedMIMETypes);
        }
        if (!isset($uri) && !isset($urls)) {
            return $this->returnError(400, 'Bad Request', "uri parameter missing");
        }

        $vocid = $vocab ? $vocab->getId() : null;
        $results = $this->model->getRDF($vocid, $uri, $format);

        if ($format == 'application/ld+json' || $format == 'application/json') {
            // further compact JSON-LD document using a context
            $context = array(
                'skos' => 'http://www.w3.org/2004/02/skos/core#',
                'isothes' => 'http://purl.org/iso25964/skos-thes#',
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'owl' => 'http://www.w3.org/2002/07/owl#',
                'dct' => 'http://purl.org/dc/terms/',
                'dc11' => 'http://purl.org/dc/elements/1.1/',
                'uri' => '@id',
                'type' => '@type',
                'lang' => '@language',
                'value' => '@value',
                'graph' => '@graph',
                'label' => 'rdfs:label',
                'prefLabel' => 'skos:prefLabel',
                'altLabel' => 'skos:altLabel',
                'hiddenLabel' => 'skos:hiddenLabel',
                'broader' => 'skos:broader',
                'narrower' => 'skos:narrower',
                'related' => 'skos:related',
                'inScheme' => 'skos:inScheme',
            );

            // Roundtrip to get @list syntax
            // https://github.com/NatLibFi/Skosmos/pull/369#issuecomment-161924791
            $results = \ML\JsonLD\JsonLD::toRdf($results);
            $results = \ML\JsonLD\JsonLD::fromRdf($results);

            $compactJsonLD = \ML\JsonLD\JsonLD::compact($results, json_encode($context));
            $results = \ML\JsonLD\JsonLD::toString($compactJsonLD);
        }

        header("Content-type: $format; charset=utf-8");
        echo $results;
    }

    /**
     * Used for querying labels for a uri.
     * @param Request $request
     * @return object json-ld wrapped labels.
     */
    public function label($request)
    {
        if (!$request->getUri()) {
            return $this->returnError(400, "Bad Request", "uri parameter missing");
        }

        $results = $request->getVocab()->getConceptLabel($request->getUri(), $request->getLang());
        if ($results === null) {
            return $this->returnError('404', 'Not Found', "Could not find concept <{$request->getUri()}>");
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('prefLabel' => 'skos:prefLabel', '@language' => $request->getLang()),
            'uri' => $request->getUri())
        );

        if (isset($results[$request->getLang()])) {
            $ret['prefLabel'] = $results[$request->getLang()]->getValue();
        }

        return $this->returnJson($ret);
    }

    /**
     * Used for querying broader relations for a concept.
     * @param Request $request
     * @return object json-ld wrapped broader concept uris and labels.
     */
    public function broader($request)
    {
        $results = array();
        $broaders = $request->getVocab()->getConceptBroaders($request->getUri(), $request->getLang());
        if ($broaders === null) {
            return $this->returnError('404', 'Not Found', "Could not find concept <{$request->getUri()}>");
        }

        foreach ($broaders as $object => $vals) {
            $results[] = array('uri' => $object, 'prefLabel' => $vals['label']);
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('prefLabel' => 'skos:prefLabel', 'broader' => 'skos:broader', '@language' => $request->getLang()),
            'uri' => $request->getUri(),
            'broader' => $results)
        );

        return $this->returnJson($ret);
    }

    /**
     * Used for querying broader transitive relations for a concept.
     * @param Request $request
     * @return object json-ld wrapped broader transitive concept uris and labels.
     */
    public function broaderTransitive($request)
    {
        $results = array();
        $broaders = $request->getVocab()->getConceptTransitiveBroaders($request->getUri(), $this->parseLimit(), false, $request->getLang());
        if (empty($broaders)) {
            return $this->returnError('404', 'Not Found', "Could not find concept <{$request->getUri()}>");
        }

        foreach ($broaders as $buri => $vals) {
            $result = array('uri' => $buri, 'prefLabel' => $vals['label']);
            if (isset($vals['direct'])) {
                $result['broader'] = $vals['direct'];
            }
            $results[$buri] = $result;
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('prefLabel' => 'skos:prefLabel', 'broader' => array('@id' => 'skos:broader', '@type' => '@id'), 'broaderTransitive' => array('@id' => 'skos:broaderTransitive', '@container' => '@index'), '@language' => $request->getLang()),
            'uri' => $request->getUri(),
            'broaderTransitive' => $results)
        );

        return $this->returnJson($ret);
    }

    /**
     * Used for querying narrower relations for a concept.
     * @param Request $request
     * @return object json-ld wrapped narrower concept uris and labels.
     */
    public function narrower($request)
    {
        $results = array();
        $narrowers = $request->getVocab()->getConceptNarrowers($request->getUri(), $request->getLang());
        if ($narrowers === null) {
            return $this->returnError('404', 'Not Found', "Could not find concept <{$request->getUri()}>");
        }

        foreach ($narrowers as $object => $vals) {
            $results[] = array('uri' => $object, 'prefLabel' => $vals['label']);
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('prefLabel' => 'skos:prefLabel', 'narrower' => 'skos:narrower', '@language' => $request->getLang()),
            'uri' => $request->getUri(),
            'narrower' => $results)
        );

        return $this->returnJson($ret);
    }

    /**
     * Used for querying narrower transitive relations for a concept.
     * @param Request $request
     * @return object json-ld wrapped narrower transitive concept uris and labels.
     */
    public function narrowerTransitive($request)
    {
        $results = array();
        $narrowers = $request->getVocab()->getConceptTransitiveNarrowers($request->getUri(), $this->parseLimit(), $request->getLang());
        if (empty($narrowers)) {
            return $this->returnError('404', 'Not Found', "Could not find concept <{$request->getUri()}>");
        }

        foreach ($narrowers as $nuri => $vals) {
            $result = array('uri' => $nuri, 'prefLabel' => $vals['label']);
            if (isset($vals['direct'])) {
                $result['narrower'] = $vals['direct'];
            }
            $results[$nuri] = $result;
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('prefLabel' => 'skos:prefLabel', 'narrower' => array('@id' => 'skos:narrower', '@type' => '@id'), 'narrowerTransitive' => array('@id' => 'skos:narrowerTransitive', '@container' => '@index'), '@language' => $request->getLang()),
            'uri' => $request->getUri(),
            'narrowerTransitive' => $results)
        );

        return $this->returnJson($ret);
    }

    /**
     * Used for querying broader transitive relations
     * and some narrowers for a concept in the hierarchy view.
     * @param Request $request
     * @return object json-ld wrapped hierarchical concept uris and labels.
     */
    public function hierarchy($request)
    {
        $results = $request->getVocab()->getConceptHierarchy($request->getUri(), $request->getLang());
        if (empty($results)) {
            return $this->returnError('404', 'Not Found', "Could not find concept <{$request->getUri()}>");
        }

        if ($request->getVocab()->getConfig()->getShowHierarchy()) {
            $schemes = $request->getVocab()->getConceptSchemes($request->getLang());
            foreach ($schemes as $scheme) {
                if (!isset($scheme['title']) && !isset($scheme['label']) && !isset($scheme['prefLabel'])) {
                    unset($schemes[array_search($scheme, $schemes)]);
                }

            }

            /* encode the results in a JSON-LD compatible array */
            $topconcepts = $request->getVocab()->getTopConcepts(array_keys($schemes), $request->getLang());
            foreach ($topconcepts as $top) {
                if (!isset($results[$top['uri']])) {
                    $results[$top['uri']] = array('uri' => $top['uri'], 'top' => $top['topConceptOf'], 'prefLabel' => $top['label'], 'hasChildren' => $top['hasChildren']);
                    if (isset($top['notation'])) {
                        $results[$top['uri']]['notation'] = $top['notation'];
                    }

                }
            }
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('onki' => 'http://schema.onki.fi/onki#', 'prefLabel' => 'skos:prefLabel', 'notation' => 'skos:notation', 'narrower' => array('@id' => 'skos:narrower', '@type' => '@id'), 'broader' => array('@id' => 'skos:broader', '@type' => '@id'), 'broaderTransitive' => array('@id' => 'skos:broaderTransitive', '@container' => '@index'), 'top' => array('@id' => 'skos:topConceptOf', '@type' => '@id'), 'hasChildren' => 'onki:hasChildren', '@language' => $request->getLang()),
            'uri' => $request->getUri(),
            'broaderTransitive' => $results)
        );

        return $this->returnJson($ret);
    }

    /**
     * Used for querying group hierarchy for the sidebar group view.
     * @param Request $request
     * @return object json-ld wrapped hierarchical concept uris and labels.
     */
    public function groups($request)
    {
        $results = $request->getVocab()->listConceptGroups($request->getLang());

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('onki' => 'http://schema.onki.fi/onki#', 'prefLabel' => 'skos:prefLabel', 'groups' => 'onki:hasGroup', 'childGroups' => array('@id' => 'skos:member', '@type' => '@id'), 'hasMembers' => 'onki:hasMembers', '@language' => $request->getLang()),
            'uri' => '',
            'groups' => $results)
        );

        return $this->returnJson($ret);
    }

    /**
     * Used for querying member relations for a group.
     * @param Request $request
     * @return object json-ld wrapped narrower concept uris and labels.
     */
    public function groupMembers($request)
    {
        $children = $request->getVocab()->listConceptGroupContents($request->getUri(), $request->getLang());
        if (empty($children)) {
            return $this->returnError('404', 'Not Found', "Could not find group <{$request->getUri()}>");
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('prefLabel' => 'skos:prefLabel', 'members' => 'skos:member', '@language' => $request->getLang()),
            'uri' => $request->getUri(),
            'members' => $children)
        );

        return $this->returnJson($ret);
    }

    /**
     * Used for querying narrower relations for a concept in the hierarchy view.
     * @param Request $request
     * @return object json-ld wrapped narrower concept uris and labels.
     */
    public function children($request)
    {
        $children = $request->getVocab()->getConceptChildren($request->getUri(), $request->getLang());
        if ($children === null) {
            return $this->returnError('404', 'Not Found', "Could not find concept <{$request->getUri()}>");
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('prefLabel' => 'skos:prefLabel', 'narrower' => 'skos:narrower', 'notation' => 'skos:notation', 'hasChildren' => 'onki:hasChildren', '@language' => $request->getLang()),
            'uri' => $request->getUri(),
            'narrower' => $children)
        );

        return $this->returnJson($ret);
    }

    /**
     * Used for querying narrower relations for a concept in the hierarchy view.
     * @param Request $request
     * @return object json-ld wrapped hierarchical concept uris and labels.
     */
    public function related($request)
    {
        $results = array();
        $related = $request->getVocab()->getConceptRelateds($request->getUri(), $request->getLang());
        if ($related === null) {
            return $this->returnError('404', 'Not Found', "Could not find concept <{$request->getUri()}>");
        }

        foreach ($related as $uri => $vals) {
            $results[] = array('uri' => $uri, 'prefLabel' => $vals['label']);
        }

        $ret = array_merge_recursive($this->context, array(
            '@context' => array('prefLabel' => 'skos:prefLabel', 'related' => 'skos:related', '@language' => $request->getLang()),
            'uri' => $request->getUri(),
            'related' => $results)
        );

        return $this->returnJson($ret);
    }
}
