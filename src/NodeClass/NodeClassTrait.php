<?php

namespace andreskrey\Readability\NodeClass;

use andreskrey\Readability\NodeUtility;

trait NodeClassTrait
{

    /**
     * Content score of the node. Used to determine the value of the content
     *
     * @var int
     */
    public $contentScore = 0;

    /**
     * Flag for initialized status
     *
     * @var bool
     */
    private $initialized = false;

    /**
     * Collection of regexps to check the node usability
     *
     * @var array
     */
    private $regexps = [
        'positive' => '/article|body|content|entry|hentry|h-entry|main|page|pagination|post|text|blog|story/i',
        'negative' => '/hidden|^hid$| hid$| hid |^hid |banner|combx|comment|com-|contact|foot|footer|footnote|masthead|media|meta|modal|outbrain|promo|related|scroll|share|shoutbox|sidebar|skyscraper|sponsor|shopping|tags|tool|widget/i',
    ];

    /**
     * initialized getter
     *
     * @return bool
     */
    public function isInitialized()
    {
        return $this->initialized;
    }

    /**
     * Initializer. Calculates the current score of the node and returns a full Readability object.
     *
     * @return self
     */
    public function initializeNode()
    {
        if (!$this->isInitialized()) {
            $contentScore = 0;

            switch ($this->nodeName) {
                case 'div':
                    $contentScore += 5;
                    break;

                case 'pre':
                case 'td':
                case 'blockquote':
                    $contentScore += 3;
                    break;

                case 'address':
                case 'ol':
                case 'ul':
                case 'dl':
                case 'dd':
                case 'dt':
                case 'li':
                case 'form':
                    $contentScore -= 3;
                    break;

                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                case 'th':
                    $contentScore -= 5;
                    break;
            }

            $this->contentScore = $contentScore + $this->getClassWeight();

            $this->initialized = true;
        }

        return $this;
    }

    /**
     * Override for native getAttribute method. Some nodes have the getAttribute method, some don't, so we need
     * to check first the existence of the attributes property.
     *
     * @param $attributeName string Attribute to retrieve
     *
     * @return string
     */
    public function getAttribute($attributeName)
    {
        if (!is_null($this->attributes)) {
            return parent::getAttribute($attributeName);
        }

        return '';
    }

    /**
     * Get the ancestors of the current node.
     *
     * @param int|bool $maxLevel Max amount of ancestors to get. False for all of them
     *
     * @return array
     */
    public function getNodeAncestors($maxLevel = 3)
    {
        $ancestors = [];
        $level = 0;

        $node = $this->parentNode;

        while ($node) {
            $ancestors[] = $node;
            $level++;
            if ($level === $maxLevel) {
                break;
            }
            $node = $node->parentNode;
        }

        return $ancestors;
    }

    /**
     * Returns all links from the current element.
     *
     * @return array|null
     */
    public function getAllLinks()
    {
        if ($this->nodeType === XML_TEXT_NODE) {
            return null;
        } else {
            return iterator_to_array($this->getElementsByTagName('a'));
        }
    }

    /**
     * Get the density of links as a percentage of the content
     * This is the amount of text that is inside a link divided by the total text in the node.
     *
     * @return int
     */
    public function getLinkDensity()
    {
        $linkLength = 0;
        $textLength = mb_strlen($this->getTextContent(true));

        if (!$textLength) {
            return 0;
        }

        $links = $this->getAllLinks();

        if ($links) {
            /** @var DOMElement $link */
            foreach ($links as $link) {
                $linkLength += mb_strlen($link->getTextContent(true));
            }
        }

        return $linkLength / $textLength;
    }


    /**
     * Calculates the weight of the class/id of the current element.
     *
     * @todo check for flag that lets this function run or not
     *
     * @return int
     */
    public function getClassWeight()
    {
        //        TODO To implement. How to get config from html parser from readability
//        if ($this->getConfig()->getOption('weightClasses')) {
//            return 0;
//        }
//
        $weight = 0;

        // Look for a special classname
        $class = $this->getAttribute('class');
        if (trim($class)) {
            if (preg_match($this->regexps['negative'], $class)) {
                $weight -= 25;
            }

            if (preg_match($this->regexps['positive'], $class)) {
                $weight += 25;
            }
        }

        // Look for a special ID
        $id = $this->getAttribute('id');
        if (trim($id)) {
            if (preg_match($this->regexps['negative'], $id)) {
                $weight -= 25;
            }

            if (preg_match($this->regexps['positive'], $id)) {
                $weight += 25;
            }
        }

        return $weight;
    }

    /**
     * Returns the full text of the node.
     *
     * @param bool $normalize Normalize white space?
     *
     * @return string
     */
    public function getTextContent($normalize = false)
    {
        $nodeValue = $this->nodeValue;
        if ($normalize) {
            $nodeValue = trim(preg_replace('/\s{2,}/', ' ', $nodeValue));
        }

        return $nodeValue;
    }

    /**
     * Returns the next node. First checks for childs (if the flag allows it), then for siblings, and finally
     * for parents.
     *
     * @param DOMNode|DOMText $originalNode
     * @param bool $ignoreSelfAndKids
     *
     * @return DOMNode
     */
    public function getNextNode($originalNode, $ignoreSelfAndKids = false)
    {
        /*
         * Traverse the DOM from node to node, starting at the node passed in.
         * Pass true for the second parameter to indicate this node itself
         * (and its kids) are going away, and we want the next node over.
         *
         * Calling this in a loop will traverse the DOM depth-first.
         */

        // First check for kids if those aren't being ignored
        if (!$ignoreSelfAndKids && $originalNode->firstChild) {
            return $originalNode->firstChild;
        }

        // Then for siblings...
        if ($originalNode->nextSibling) {
            return $originalNode->nextSibling;
        }

        // And finally, move up the parent chain *and* find a sibling
        // (because this is depth-first traversal, we will have already
        // seen the parent nodes themselves).
        do {
            $originalNode = $originalNode->parentNode;
        } while ($originalNode && !$originalNode->nextSibling);

        return ($originalNode) ? $originalNode->nextSibling : $originalNode;
    }

    /**
     * Compares nodes. Checks for tag name and text content.
     *
     * It's a replacement of the original JS code, which looked like this:
     *
     * $node1 == $node2
     *
     * I'm not sure this works the same in PHP, so I created a mock function to check the actual content of the node.
     * Should serve the same porpuse as the original comparison.
     *
     * @param DOMElement $node1
     * @param DOMElement $node2
     *
     * @return bool
     */
    public function compareNodes($node1, $node2)
    {
        if ($node1->nodeName !== $node2->nodeName) {
            return false;
        }

        if ($node1->getTextContent(true) !== $node2->getTextContent(true)) {
            return false;
        }

        return true;
    }

    /**
     * Creates a new node based on the text content of the original node.
     *
     * @param $originalNode DOMElement
     * @param $tagName string
     *
     * @return DOMElement
     */
    public function createNode($originalNode, $tagName)
    {
        $text = NodeUtility::getTextContent($originalNode);
        $newNode = $originalNode->ownerDocument->createElement($tagName, $text);

        return $newNode;
    }

    /**
     * Check if a given node has one of its ancestor tag name matching the
     * provided one.
     *
     * @param Readability $node
     * @param string $tagName
     * @param int $maxDepth
     *
     * @return bool
     */
    public function hasAncestorTag(self $node, $tagName, $maxDepth = 3)
    {
        $depth = 0;
        while ($node->parentNode) {
            if ($maxDepth > 0 && $depth > $maxDepth) {
                return false;
            }
            if ($node->parentNode->nodeName === $tagName) {
                return true;
            }
            $node = $node->parentNode;
            $depth++;
        }

        return false;
    }

    /**
     * Returns the children of the current node.
     *
     * @param bool $filterEmptyDOMText Filter empty DOMText nodes?
     *
     * @return array
     */
    public function getChildren($filterEmptyDOMText = false)
    {
        $ret = [];
        foreach ($this->childNodes as $node) {
            if ($filterEmptyDOMText && $node->nodeName === '#text' && !trim($node->nodeValue)) {
                continue;
            }

            $ret[] = $node;
        }

        return $ret;
    }

    /**
     * Determines if a node has no content or it is just a bunch of dividing lines and/or whitespace.
     *
     * @return bool
     */
    public function isElementWithoutContent()
    {
        return $this instanceof DOMElement &&
            // /\x{00A0}|\s+/u TODO to be replaced with regexps array
            mb_strlen(preg_replace('/\x{00A0}|\s+/u', '', $this->textContent)) === 0 &&
            ($this->childNodes->length === 0 ||
                $this->childNodes->length === $this->getElementsByTagName('br')->length + $this->getElementsByTagName('hr')->length
                /*
                 * Special DOMDocument case: We also need to count how many DOMText we have inside the node.
                 * If there's an empty tag with an space inside and a BR (for example "<p> <br/></p>) counting only BRs and
                 * HRs will will say that the example has 2 nodes, instead of one. This happens because in DOMDocument,
                 * DOMTexts are also nodes (which doesn't happen in JS). So we need to also count how many DOMText we
                 * are dealing with (And at this point we know they are empty or are just whitespace, because of the
                 * mb_strlen in this chain of checks).
                 */
                + count(array_filter(iterator_to_array($this->childNodes), function ($child) {
                    return $child instanceof DOMText;
                }))

            );
    }

    /**
     * Return an array indicating how many rows and columns this table has.
     *
     * @return array
     */
    public function _getRowAndColumnCount()
    {
        $rows = $columns = 0;
        $trs = $this->getElementsByTagName('tr');
        foreach ($trs as $tr) {
            /** @var \DOMElement $tr */
            $rowspan = $tr->getAttribute('rowspan');
            $rows += ($rowspan || 1);

            // Now look for column-related info
            $columnsInThisRow = 0;
            $cells = $tr->getElementsByTagName('td');
            foreach ($cells as $cell) {
                /** @var \DOMElement $cell */
                $colspan = $cell->getAttribute('colspan');
                $columnsInThisRow += ($colspan || 1);
            }
            $columns = max($columns, $columnsInThisRow);
        }

        return ['rows' => $rows, 'columns' => $columns];
    }
}
