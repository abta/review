<?php
/**
 * This file is part of qaReview
 *
 * @version $Revision$
 * @copyright Qafoo GmbH
 */

namespace Qafoo\Review;

/**
 * Return highlighted code
 *
 * @version $Revision$
 */
class CodeProcessor
{
    /**
     * Contents of highlighted file
     *
     * @var string
     */
    protected $contents;

    /**
     * Array containing all annotations.
     *
     * Array is maintained as an array array of annotations per line number.
     *
     * @var Struct\Annotation[][]
     */
    protected $annotations;

    /**
     * Mapping of colors to semantic styles
     *
     * @var array
     */
    protected $styleMapping = array(
        '#0000bb' => 'code',
        '#ff8000' => 'comment',
        '#007700' => 'keyword',
        '#000000' => 'html',
        '#dd0000' => 'string',
    );

    /**
     * Load file
     *
     * @param string $file
     * @return void
     */
    public function load( $file )
    {
        $this->content = highlight_file( $file, true );

        $this->content = $this->convertWhitespaces( $this->content );
        $this->content = $this->removeBullshitMarkup( $this->content );
        $this->content = $this->convertStyles( $this->content );
        $this->content = $this->splitLines( $this->content );
    }

    /**
     * Convert whitespace
     *
     * @param string $content
     * @return string
     */
    protected function convertWhitespaces( $content )
    {
        $content = str_replace( '<br />', "\n", $content );
        $content = str_replace( '&nbsp;', ' ', $content );
        return $content;
    }

    /**
     * Remove bullshit markup
     *
     * @param string $content
     * @return string
     */
    protected function removeBullshitMarkup( $content )
    {
        $content = preg_replace( '(^<code[^>]*>\\s*)', '', $content );
        $content = preg_replace( '(\\s*</code[^>]*>\\s*$)', '', $content );

        $content = preg_replace( '(^<span[^>]*>\\s*)', '', $content );
        $content = preg_replace( '(\\s*</span[^>]*>\\s*$)', '', $content );

        return $content;
    }

    /**
     * Convert styles
     *
     * @param string $content
     * @return string
     */
    protected function convertStyles( $content )
    {
        $styleMapping = $this->styleMapping;
        return preg_replace_callback(
            '(<span[^>]*style="color:\\s*(?P<color>#[A-Fa-f0-9]{3,6})"[^>]*>)',
            function ( $matches ) use ( $styleMapping )
            {
                $color = strtolower( $matches['color'] );
                if ( !isset( $styleMapping[$color] ) )
                {
                    throw new \Exception( "No style available for $color." );
                }

                return sprintf(
                    '<span class="%s">',
                    $styleMapping[$color]
                );
            },
            $content
        );
    }

    /**
     * Split content into lines
     *
     * Ensures tags are properly opened and closed per line.
     *
     * @param string $content
     * @return array
     */
    protected function splitLines( $content )
    {
        $lines = preg_split( '(\r\n|\r|\n)', $content );
        $stack = array();

        foreach ( $lines as $nr => $line )
        {
            $result = '';
            foreach ( $stack as $class )
            {
                $result .= "<span class=\"$class\">";
            }

            preg_match_all( '(<(/?)span\\s*(?:class="([^"]*)")?>)', $line, $matches, \PREG_SET_ORDER );
            foreach ( $matches as $span )
            {
                if ( $span[1] === '/' )
                {
                    array_pop( $stack );
                }
                else
                {
                    array_push( $stack, $span[2] );
                }
            }

            $result .= rtrim( $line );
            foreach ( $stack as $class )
            {
                $result .= '</span>';
            }

            $lines[$nr] = $result;
        }

        return $lines;
    }

    /**
     * Add annotations to source code
     *
     * @param Struct\Annotation[] $annotations
     * @return void
     */
    public function addAnnotations( array $annotations )
    {
        foreach ( $annotations as $annotation )
        {
            $this->annotations[$annotation->line - 1][] = $annotation;
        }
    }

    /**
     * Get highlighted file as HTML
     *
     * @return string
     */
    public function getSourceData()
    {
        $lines = array();
        foreach ( $this->content as $nr => $line )
        {
            $annotations    = isset( $this->annotations[$nr] ) ? $this->annotations[$nr] : array();
            $lines[$nr + 1] = array(
                'content' => $line,
                'annotations' => $annotations,
                'messages'    => array_filter(
                    $annotations,
                    function ( $annotation )
                    {
                        return strlen( $annotation->message );
                    }
                ),
            );
        }

        return $lines;
    }

    /**
     * Get index for for file
     *
     * @return void
     */
    public function getIndex()
    {
        $index = array();

        foreach ( $this->content as $nr => $line )
        {
            switch ( true )
            {
                case preg_match( '(function\\s+(?P<name>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*))', strip_tags( $line ), $match ):
                    $index[] = array(
                        'type' => 'function',
                        'line' => $nr + 1,
                        'name' => $match['name'],
                    );
                    break;

                case preg_match( '(^\\s*(?:abstract\\s+)?class\\s+(?P<name>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*))', strip_tags( $line ), $match ):
                    $index[] = array(
                        'type' => 'class',
                        'line' => $nr + 1,
                        'name' => $match['name'],
                    );
                    break;

                case preg_match( '(interface\\s+(?P<name>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*))', strip_tags( $line ), $match ):
                    $index[] = array(
                        'type' => 'interface',
                        'line' => $nr + 1,
                        'name' => $match['name'],
                    );
                    break;
            }
        }

        return $index;
    }
}

