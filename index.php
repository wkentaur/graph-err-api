<?php

use Silex\Application;
use Symfony\Component\HttpFoundation\Request,
    Symfony\Component\HttpFoundation\JsonResponse,
    Symfony\Component\Yaml\Yaml;
use GraphAware\Neo4j\Client\ClientBuilder;

require __DIR__.'/vendor/autoload.php';

$app = new Application();


$config = Yaml::parse(file_get_contents('/home/ocean/.errwebconfig.yml'));
$cnx = parse_url($config['neo4j_url']);

//->addConnection('default', $cnx['scheme'], $cnx['host'], $cnx['port'], true, $cnx['user'], $cnx['pass'])

$neo4j = ClientBuilder::create()
    ->addConnection('default', $config['neo4j_url']) 
    ->setDefaultTimeout(20)
    ->build();

$app->get('/', function () {
    return file_get_contents(__DIR__.'/static/index.html');
});

$app->get('/search', function (Request $request) use ($neo4j) {
    $searchTerm = $request->get('q');
    $query = 'MATCH (t:Term) WHERE LOWER(t.text) CONTAINS LOWER({searchText}) RETURN t.id, t.text, t.type ORDER BY t.incoming desc LIMIT 10';
    $params = ['searchText' => $searchTerm];

    $result = $neo4j->run($query, $params);
    $outTerms = [];
    foreach ($result->getRecords() as $record){
        $outTerms[] = [ 'id' => $record->value('t.id'), 'text' => $record->value('t.text'), 'type' => $record->value('t.type') ];
    }
    $outRes = ['result' => $outTerms];

    $response = new JsonResponse();
    $response->setData($outRes);

    return $response;
});

$app->get('/related', function (Request $request) use ($neo4j) {
    $getId = $request->get('id');
    $outRes = [];
    if ($getId) {
        $query = 'MATCH (t:Term {id: {term} })--(:LocalWord)--(s:Sentence)--(:LocalWord)--(related:Term) '
                .'WHERE related <> t '
                .'RETURN distinct related.id, related.text, related.type, count(s) as scount '
                .'ORDER BY scount DESC LIMIT 10 ';
        $params = ['term' => $getId];

        $result = $neo4j->run($query, $params);
        $outTerms = [];
        foreach ($result->getRecords() as $record){
            $outTerms[] = [ 'id' => $record->value('related.id'), 'text' => $record->value('related.text'), 'type' => $record->value('related.type') ];
        }
        $outRes = ['result' => $outTerms];

    }
    
    $response = new JsonResponse();
    $response->setData($outRes);

    return $response;
});

$app->get('/term', function (Request $request) use ($neo4j) {
    $getId = $request->get('id');
    $outRes = [];
    if ($getId) {
        $query = 'MATCH (t:Term {id: {term}})--(:LocalWord)--(:Sentence)--(n:Nstory)-->(d:Day)<--(m:Month)<--(y:Year) '
                 .'RETURN distinct n.url, n.title, y.value, m.value, d.value, n.pubDaySec '
                 .'ORDER BY y.value desc, m.value desc, d.value desc, n.pubDaySec desc LIMIT 100';
        $params = ['term' => $getId];

        $result = $neo4j->run($query, $params);
        $outNews = [];
        foreach ($result->getRecords() as $record){
            $strDate = sprintf('%d', $record->value('y.value')) 
                       . '-' . sprintf('%02d', $record->value('m.value'))
                       . '-' . sprintf('%02d', $record->value('d.value'));   
            $outNews[] = [ 'url' => $record->value('n.url'), 'title' => $record->value('n.title'),
                           'published' => $strDate ];
        }
        $outRes = ['result' => $outNews];

    }
    
    $response = new JsonResponse();
    $response->setData($outRes);

    return $response;
});

//debug only
//$app->error(function(\Exception $e) use ($app) {
//    print $e->getMessage(); // Do something with $e
//});

$app->run();
