<?php

namespace Symfony\Cmf\Bundle\SearchBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

use PHPCR\Util\QOM\QueryBuilder;

use Liip\SearchBundle\SearchInterface;
use Liip\SearchBundle\Helper\SearchParams;

use Doctrine\Common\Persistence\ManagerRegistry;

class PhpcrSearchController implements SearchInterface
{
    protected $manager;
    protected $managerName;
    protected $router;
    protected $templating;
    protected $perPage;
    protected $restrictByLanguage;
    protected $translationDomain;
    protected $pageParameterKey;
    protected $queryParameterKey;
    protected $searchRoute;
    protected $searchFields = array('title' => 'title', 'summary' => 'body');
    protected $searchPath = '/cms/content';
    protected $translationStrategy;

    /**
     * @param ManagerRegistry $registry
     * @param string $managerName
     * @param EngineInterface $templating
     * @param integer $perPage
     * @param boolean $restrictByLanguage
     * @param string $translationDomain
     * @param string $pageParameterKey parameter name used for page
     * @param string $queryParameterKey parameter name used for search term
     * @param string $searchRoute route used for submitting search query
     * @param string $searchPath search path
     * @param array $searchFields array that contains keys 'title'/'summary' with a mapping to property names to search
     * @param null|string $translationStrategy null, attribute, child
     */
    public function __construct(ManagerRegistry $registry, $managerName, RouterInterface $router, EngineInterface $templating, $perPage, $restrictByLanguage,
        $translationDomain, $pageParameterKey, $queryParameterKey, $searchRoute, $searchPath, $searchFields, $translationStrategy)
    {
        $this->registry = $registry;
        $this->managerName = $managerName;
        $this->router = $router;
        $this->templating = $templating;
        $this->perPage = $perPage;
        $this->restrictByLanguage = $restrictByLanguage;
        $this->translationDomain = $translationDomain;
        $this->pageParameterKey = $pageParameterKey;
        $this->queryParameterKey = $queryParameterKey;
        $this->searchRoute = $searchRoute;
        $this->searchFields = $searchFields;
        $this->searchPath = $searchPath;
        $this->translationStrategy = $translationStrategy;
    }

    /**
     * Search method
     *
     * @param mixed $query string current search query or null
     * @param mixed $page string current result page to show or null
     * @param mixed $lang string language to use for restricting search results, or null
     * @param array $options any options which should be passed along to underlying search engine
     * @param Request current request object, will be automatically injected by symfony when called as an action
     *
     * @return Response
     */
    public function searchAction($query = null, $page = null, $lang = null, $options = array(), Request $request = null)
    {
        if (null === $page) {
            // If the page param is not given, it's value is read in the request
            $page = SearchParams::requestedPage($request, $this->pageParameterKey);
        }

        if (null === $query) {
            // If the query param is not given, it's value is read in the request
            $query = SearchParams::requestedQuery($request, $this->queryParameterKey);
        }

        $lang = $this->queryLanguage($lang, $request);

        if ('' !== $query) {
            /** @var $dm \Doctrine\ODM\PHPCR\DocumentManager */
            $dm = $this->registry->getManager($this->managerName);
            // TODO: use createQueryBuilder to use the ODM builder once it has all features we need
            $qb = $dm->createPhpcrQueryBuilder();
            $this->buildQuery($qb, $query, $page, $lang);
            $searchResults = $this->buildSearchResults($dm->getPhpcrSession(), $qb->execute());
        } else {
            $searchResults = array();
        }

        $params = array(
            'searchTerm' => $query,
            'searchResults' => $searchResults,
            'estimated' => count($searchResults),
            'translationDomain' => $this->translationDomain,
            'showPaging' => false,
            'start' => $page,
            'perPage' => $this->perPage,
            'searchRoute' => $this->searchRoute,
        );

        return new Response($this->templating->render('LiipSearchBundle:Search:search.html.twig', $params));
    }

    /**
     * @param QueryBuilder $qb
     * @param $query
     * @param integer $page
     * @param string $lang
     */
    private function buildQuery(QueryBuilder $qb, $query, $page, $lang)
    {
        $factory = $qb->getQOMFactory();

        $qb->select('jcr:uuid')
            ->addSelect('phpcr:class')
            ->from($factory->selector('nt:unstructured'))
            ->where($factory->descendantNode($this->searchPath))
            ->setFirstResult(($page - 1) * $this->perPage)
            ->setMaxResults($this->perPage);

        $constraint = null;
        foreach ($this->searchFields as $field) {
            if (2 === strlen($lang) && 'attribute' === $this->translationStrategy) {

            }
            $qb->addSelect($field);
            $newConstraint = $factory->fullTextSearch($field, $query);
            if (empty($constraint)) {
                $constraint = $newConstraint;
            } else {
                $constraint = $factory->orConstraint($constraint, $newConstraint);
            }
        }
        $qb->andWhere($constraint);

        if (2 === strlen($lang) && 'child' === $this->translationStrategy) {
            // TODO: check if we can/must validate lang to prevent evil hacking or accidental breakage
            $qb->andWhere($factory->comparison($factory->nodeName('[nt:unstructured]'), '=', $factory->literal("phpcr_locale:".$lang)));
        }
    }

    private function buildSearchResults($session, $rows)
    {
        $searchResults = array();
        foreach ($rows as $row) {
            if (!$row->getValue('phpcr:class')) {
                $parent = $session->getNode(dirname($row->getPath()));
                $contentId = $parent->getIdentifier();
            } else {
                $contentId = $row->getValue('jcr:uuid') ? $row->getValue('jcr:uuid') : $row->getPath();
            }

            $searchResults[$contentId] = array(
                'url' => $this->router->generate(null, array('content_id' => $contentId)),
                'title' => $row->getValue($this->searchFields['title']),
                'summary' => substr(strip_tags($row->getValue($this->searchFields['summary'])), 0, 100),
            );
        }

        return $searchResults;
    }

    /**
     * Determine language used to restrict search results, if one should be used at all.
     * If $this->restrictByLanguage is false, this will return false.
     *
     * @return mixed string(=locale) or bool(=false)
     */
    public function queryLanguage($lang = null, Request $request)
    {
        if (!$this->restrictByLanguage) {
            return false;
        }

        if (null !== $lang) {
            return $lang;
        }

        return $request->getLocale();
    }
}
