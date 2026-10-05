<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;

/* table/search/column_comparison_operators.twig */
class __TwigTemplate_6c9e1d4771e53c16c8441dc0002e130f extends Template
{
    private $source;
    private $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = [])
    {
        $macros = $this->macros;
        // line 1
        yield "<select class=\"column-operator\" id=\"ColumnOperator";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["search_index"] ?? null), "html", null, true);
        yield "\" name=\"criteriaColumnOperators[";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["search_index"] ?? null), "html", null, true);
        yield "]\">
    ";
        // line 2
        yield ($context["type_operators"] ?? null);
        yield "
</select>
";
        return; yield '';
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName()
    {
        return "table/search/column_comparison_operators.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable()
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo()
    {
        return array (  45 => 2,  38 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "table/search/column_comparison_operators.twig", "/var/www/html/phpmyadmin/templates/table/search/column_comparison_operators.twig");
    }
}
