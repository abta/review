<?php
/**
 * This file is part of qaReview
 *
 * @version $Revision$
 * @copyright Qafoo GmbH
 */

namespace Qafoo\Review\Analyzer;
use Qafoo\Review\Analyzer;
use Qafoo\Review\AnnotationGateway;
use Qafoo\Review\Struct;
use Qafoo\Review\Displayable;
use Qafoo\Review\Chart;
use Qafoo\RMF;

/**
 * PDepend analyzer class
 *
 * @version $Revision$
 */
class PDepend extends Analyzer implements Displayable
{
    /**
     * Result directory
     *
     * @var string
     */
    protected $resultDir;

    /**
     * Annotation gateway
     *
     * @var AnnotationGateway
     */
    protected $gateway;

    /**
     * Create from annotation gateway
     *
     * @param string $resultDir
     * @param AnnotationGateway $gateway
     * @return void
     */
    public function __construct( $resultDir, AnnotationGateway $gateway )
    {
        $this->resultDir = $resultDir;
        $this->gateway   = $gateway;
    }

    /**
     * Analyze source
     *
     * @param string $path
     * @return void
     */
    public function analyze( $path )
    {
        $process = new \SystemProcess\SystemProcess( 'pdepend' );
        $process->nonZeroExitCodeException = true;
        $process
            ->argument( '--jdepend-chart=' . $this->resultDir . '/pdepend_jdepend.svg' )
            ->argument( '--overview-pyramid=' . $this->resultDir . '/pdepend_pyramid.svg' )
            ->argument( '--arbit-xml=' . $this->resultDir . '/pdepend_summary.xml' )
            ->argument( $path );

        $process->execute();

        $this->processAnnotations( $path, $this->resultDir . '/pdepend_summary.xml' );
    }

    /**
     * Process annotations from summary XML file
     *
     * @param string $path
     * @param string $summaryXml
     * @return void
     */
    protected function processAnnotations( $path, $summaryXml )
    {
        $doc = new \DOMDocument();
        $doc->load( $summaryXml );
        $xpath = new \DOMXPath( $doc );

        // Replace all pathes in summary.xml with relative pathes
        foreach ( $xpath->query( '//file' ) as $fileNode )
        {
            $fileNode->setAttribute( 'name', str_replace( $path, '', $fileNode->getAttribute( 'name' ) ) );
        }
        $doc->save( $summaryXml );

        $model = new PDepend\Model( $summaryXml );
        foreach ( $model->getAnnotations() as $annotation )
        {
            $this->gateway->create( $annotation );
        }
    }

    /**
     * Get summary
     *
     * @return Struct\Summary
     */
    public function getSummary()
    {
        return new Struct\Summary(
            'PDepend',
            'Displays class and method metrics as a tag cloud. This allows you to locate violations quickly.'
        );
    }

    /**
     * Check if menu should be show at all
     *
     * @return bool
     */
    public function displayable()
    {
        return is_file( $this->resultDir . '/pdepend_summary.xml' );
    }

    /**
     * Get menu entry
     *
     * @return Struct\MenuEntry
     */
    public function getMenuEntry()
    {
        return new Struct\MenuEntry( 'PDepend' );
    }

    /**
     * Render yourself
     *
     * @param RMF\Request $request
     * @return Struct\Response
     */
    public function render( RMF\Request $request )
    {
        if ( !is_file( $this->resultDir . '/pdepend_summary.xml' ) )
        {
            return new Struct\Response(
                'not_available.twig',
                array(
                    'summary'  => $this->getSummary(),
                )
            );
        }

        if ( isset( $request->variables['chart'] ) )
        {
            return $this->renderChart( $request );
        }

        $classMetric  = isset( $request->variables['class'] ) ? $request->variables['class'] : 'cr';
        $methodMetric = isset( $request->variables['method'] ) ? $request->variables['method'] : 'ccn';

        $model = new PDepend\Model( $this->resultDir . '/pdepend_summary.xml' );

        $classCloud = $model->getClassesMetric( $classMetric );
        $classTop   = $model->limitItemList( $classCloud['items'], 15 );

        $methodCloud = $model->getMethodsMetric( $methodMetric );
        $methodTop   = $model->limitItemList( $methodCloud['items'], 15 );

        return new Struct\Response(
            'pdepend.twig',
            array(
                'class'         => $classCloud,
                'classTop'      => $classTop,
                'classMetrics'  => $model->getClassMetricList(),
                'method'        => $methodCloud,
                'methodTop'     => $methodTop,
                'methodMetrics' => $model->getMethodMetricList(),
                'pyramid'       => file_get_contents( $this->resultDir . '/pdepend_pyramid.svg' ),
                'jdepend'       => file_get_contents( $this->resultDir . '/pdepend_jdepend.svg' ),
            )
        );
    }

    /**
     * renderChart
     *
     * @param RMF\Request $request
     * @return void
     */
    public function renderChart( RMF\Request $request )
    {
        $model = new PDepend\Model( $this->resultDir . '/pdepend_summary.xml' );
        $method  = $request->variables['chart'] === 'class' ? 'getClassesMetric' : 'getMethodsMetric';
        $metrics = $model->$method( $request->variables['metric'], pow( 2, 31 ) );

        $graph = new Chart\LineChart( $metrics['name'] );
        $graph->options->lineThickness = 0;

        $graph->xAxis = new \ezcGraphChartElementNumericAxis();
        $graph->xAxis->label = $request->variables['metric'];
        $graph->yAxis->label = '#';

        $graph->data[$metrics['name']] = new \ezcGraphArrayDataSet( array_count_values( array_map(
            function( $item ) {
                return (int) $item['value'];
            },
            $metrics['items']
        ) ) );
        $graph->data[$metrics['name']]->symbol = \ezcGraph::BULLET;

        header( 'Content-Type: image/svg+xml' );
        $graph->renderToOutput( 600, 350 );
        exit( 0 );
    }
}

