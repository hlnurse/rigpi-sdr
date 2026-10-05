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

/* server/replication/index.twig */
class __TwigTemplate_f8761d16c5d1d72af99871c2c9b1f589 extends Template
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
        yield "<div class=\"container-fluid\">
<div class=\"row\">
<h2>
  ";
        // line 4
        yield PhpMyAdmin\Html\Generator::getImage("s_replication");
        yield "
  ";
yield _gettext("Replication");
        // line 6
        yield "</h2>
</div>

";
        // line 9
        if (($context["is_super_user"] ?? null)) {
            // line 10
            yield "<div class=\"row\">
  <div id=\"replication\" class=\"container-fluid\">
    ";
            // line 12
            yield ($context["error_messages"] ?? null);
            yield "

    ";
            // line 14
            if (($context["is_primary"] ?? null)) {
                // line 15
                yield "      ";
                yield ($context["primary_replication_html"] ?? null);
                yield "
    ";
            } elseif (((null ===             // line 16
($context["primary_configure"] ?? null)) && (null === ($context["clear_screen"] ?? null)))) {
                // line 17
                yield "      <div class=\"card mb-2\">
        <div class=\"card-header\">";
yield _gettext("Primary replication");
                // line 18
                yield "</div>
        <div class=\"card-body\">
        ";
                // line 20
                $___internal_parse_1_ = ('' === $tmp = \Twig\Extension\CoreExtension::captureOutput((function () use (&$context, $macros, $blocks) {
                    // line 21
                    yield "          ";
yield _gettext("This server is not configured as primary in a replication process. Would you like to %sconfigure%s it?");
                    // line 24
                    yield "        ";
                    return; yield '';
                })())) ? '' : new Markup($tmp, $this->env->getCharset());
                // line 20
                yield Twig\Extension\CoreExtension::sprintf($___internal_parse_1_, (((("<a href=\"" . PhpMyAdmin\Url::getFromRoute("/server/replication")) . "\" data-post=\"") . PhpMyAdmin\Url::getCommon(Twig\Extension\CoreExtension::merge(($context["url_params"] ?? null), ["primary_configure" => true]), "", false)) . "\">"), "</a>");
                // line 25
                yield "        </div>
      </div>
    ";
            }
            // line 28
            yield "
    ";
            // line 29
            if ( !(null === ($context["primary_configure"] ?? null))) {
                // line 30
                yield "      ";
                yield ($context["primary_configuration_html"] ?? null);
                yield "
    ";
            } else {
                // line 32
                yield "      ";
                if ((null === ($context["clear_screen"] ?? null))) {
                    // line 33
                    yield "        ";
                    yield ($context["replica_configuration_html"] ?? null);
                    yield "
      ";
                }
                // line 35
                yield "      ";
                if ( !(null === ($context["replica_configure"] ?? null))) {
                    // line 36
                    yield "        ";
                    yield ($context["change_primary_html"] ?? null);
                    yield "
      ";
                }
                // line 38
                yield "    ";
            }
            // line 39
            yield "  </div>
</div>
</div>
";
        } else {
            // line 43
            yield "  ";
            yield $this->env->getFilter('error')->getCallable()(_gettext("No privileges"));
            yield "
";
        }
        return; yield '';
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName()
    {
        return "server/replication/index.twig";
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
        return array (  135 => 43,  129 => 39,  126 => 38,  120 => 36,  117 => 35,  111 => 33,  108 => 32,  102 => 30,  100 => 29,  97 => 28,  92 => 25,  90 => 20,  86 => 24,  83 => 21,  81 => 20,  77 => 18,  73 => 17,  71 => 16,  66 => 15,  64 => 14,  59 => 12,  55 => 10,  53 => 9,  48 => 6,  43 => 4,  38 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "server/replication/index.twig", "/var/www/html/phpmyadmin/templates/server/replication/index.twig");
    }
}
