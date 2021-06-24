<?php

declare(strict_types=1);

namespace Chubbyphp\Framework\Router\HackRouting;

use Chubbyphp\Framework\Router\Exceptions\MissingAttributeForPathGenerationException;
use Chubbyphp\Framework\Router\Exceptions\MissingRouteByNameException;
use Chubbyphp\Framework\Router\Exceptions\NotMatchingValueForPathGenerationException;
use Chubbyphp\Framework\Router\HackRouting\RouteParser\RouteParser;
use Chubbyphp\Framework\Router\HackRouting\RouteParser\RouteParserInterface;
use Chubbyphp\Framework\Router\RouteInterface;
use Chubbyphp\Framework\Router\RoutesInterface;
use Chubbyphp\Framework\Router\UrlGeneratorInterface;
use HackRouting\PatternParser\LiteralNode;
use HackRouting\PatternParser\OptionalNode;
use HackRouting\PatternParser\ParameterNode;
use HackRouting\PatternParser\PatternNode;
use Psr\Http\Message\ServerRequestInterface;

final class UrlGenerator implements UrlGeneratorInterface
{
    /**
     * @var array<string, RouteInterface>
     */
    private array $routesByName;

    private RouteParserInterface $routeParser;

    private string $basePath;

    public function __construct(RoutesInterface $routes, ?RouteParserInterface $routeParser = null, string $basePath = '')
    {
        $this->routesByName = $routes->getRoutesByName();
        $this->routeParser = $routeParser ?? new RouteParser();
        $this->basePath = $basePath;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, mixed>  $queryParams
     */
    public function generateUrl(
        ServerRequestInterface $request,
        string $name,
        array $attributes = [],
        array $queryParams = []
    ): string {
        $uri = $request->getUri();
        $requestTarget = $this->generatePath($name, $attributes, $queryParams);

        return $uri->getScheme().'://'.$uri->getAuthority().$requestTarget;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, mixed>  $queryParams
     */
    public function generatePath(string $name, array $attributes = [], array $queryParams = []): string
    {
        $path = $this->pathFromNodes($this->getParsedRouteByName($name), $name, $attributes);

        if ([] === $queryParams) {
            return $this->basePath.$path;
        }

        return $this->basePath.$path.'?'.\http_build_query($queryParams);
    }

    private function getParsedRouteByName(string $name): PatternNode
    {
        if (!isset($this->routesByName[$name])) {
            throw MissingRouteByNameException::create($name);
        }

        return $this->routeParser->parse($this->routesByName[$name]);
    }

    /**
     * @param array<string, string> $attributes
     */
    private function pathFromNodes(PatternNode $patternNode, string $name, array $attributes): string
    {
        $path = '';
        foreach ($patternNode->getChildren() as $childNode) {
            if ($childNode instanceof PatternNode) {
                $path .= $this->pathFromNodes($childNode, $name, $attributes);
            } elseif ($childNode instanceof LiteralNode) {
                $path .= $childNode->getText();
            } elseif ($childNode instanceof ParameterNode) {
                $path .= $this->getAttributeValue($childNode, $name, $attributes);
            } elseif ($childNode instanceof OptionalNode) {
                try {
                    $path .= $this->pathFromNodes($childNode->getPattern(), $name, $attributes);
                } catch (MissingAttributeForPathGenerationException $e) {
                }
            } else {
                throw new \Exception('Unknown node type "%s"', get_class($childNode));
            }
        }

        return $path;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function getAttributeValue(ParameterNode $parameterNode, string $name, array $attributes): string
    {
        $attribute = $parameterNode->getName();

        if (!isset($attributes[$attribute])) {
            throw MissingAttributeForPathGenerationException::create($name, $attribute);
        }

        $value = (string) $attributes[$attribute];

        $regexp = $parameterNode->getRegexp();

        $pattern = '!^'.$regexp.'$!';

        if (null !== $regexp && 1 !== \preg_match($pattern, $value)) {
            throw NotMatchingValueForPathGenerationException::create(
                $name,
                $attribute,
                $value,
                $regexp
            );
        }

        return $value;
    }
}
