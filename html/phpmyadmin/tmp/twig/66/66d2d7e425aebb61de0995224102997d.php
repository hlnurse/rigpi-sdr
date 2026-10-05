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

/* server/replication/replica_configuration.twig */
class __TwigTemplate_f171a5c029cfe679c78ba69d3f60d180 extends Template
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
        yield "<div class=\"card\">
  <div class=\"card-header\">";
yield _gettext("Replica replication");
        // line 2
        yield "</div>
  <div class=\"card-body\">
  ";
        // line 4
        if (($context["server_replica_multi_replication"] ?? null)) {
            // line 5
            yield "    ";
yield _gettext("Primary connection:");
            // line 6
            yield "    <form method=\"get\" action=\"";
            yield PhpMyAdmin\Url::getFromRoute("/server/replication");
            yield "\">
      ";
            // line 7
            yield PhpMyAdmin\Url::getHiddenInputs(($context["url_params"] ?? null));
            yield "
      <select name=\"primary_connection\">
        <option value=\"\">";
yield _gettext("Default");
            // line 9
            yield "</option>
        ";
            // line 10
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["server_replica_multi_replication"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["server"]) {
                // line 11
                yield "          <option value=\"";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape((($__internal_compile_0 = $context["server"]) && is_array($__internal_compile_0) || $__internal_compile_0 instanceof ArrayAccess ? ($__internal_compile_0["Connection_name"] ?? null) : null), "html", null, true);
                yield "\"";
                yield (((($context["primary_connection"] ?? null) == (($__internal_compile_1 = $context["server"]) && is_array($__internal_compile_1) || $__internal_compile_1 instanceof ArrayAccess ? ($__internal_compile_1["Connection_name"] ?? null) : null))) ? (" selected") : (""));
                yield ">
            ";
                // line 12
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape((($__internal_compile_2 = $context["server"]) && is_array($__internal_compile_2) || $__internal_compile_2 instanceof ArrayAccess ? ($__internal_compile_2["Connection_name"] ?? null) : null), "html", null, true);
                yield "
          </option>
        ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_iterated'], $context['_key'], $context['server'], $context['_parent'], $context['loop']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 15
            yield "      </select>
      <input id=\"goButton\" class=\"btn btn-primary\" type=\"submit\" value=\"";
yield _gettext("Go");
            // line 16
            yield "\">
    </form>
    <br>
    <br>
  ";
        }
        // line 21
        yield "
  ";
        // line 22
        if (($context["server_replica_status"] ?? null)) {
            // line 23
            yield "    <div id=\"replica_configuration_gui\">
      ";
            // line 24
            if ( !($context["replica_sql_running"] ?? null)) {
                // line 25
                yield "        ";
                yield $this->env->getFilter('error')->getCallable()(_gettext("Replica SQL Thread not running!"));
                yield "
      ";
            }
            // line 27
            yield "      ";
            if ( !($context["replica_io_running"] ?? null)) {
                // line 28
                yield "        ";
                yield $this->env->getFilter('error')->getCallable()(_gettext("Replica IO Thread not running!"));
                yield "
      ";
            }
            // line 30
            yield "
      <p>";
yield _gettext("Server is configured as replica in a replication process. Would you like to:");
            // line 31
            yield "</p>
      <ul>
        <li>
          <a href=\"#replica_status_href\" id=\"replica_status_href\">";
yield _gettext("See replica status table");
            // line 34
            yield "</a>
          ";
            // line 35
            yield ($context["replica_status_table"] ?? null);
            yield "
        </li>
        <li>
          <a href=\"#replica_control_href\" id=\"replica_control_href\">";
yield _gettext("Control replica:");
            // line 38
            yield "</a>
          <div id=\"replica_control_gui\" class=\"hide\">
            <ul>
              <li>
                <a href=\"";
            // line 42
            yield PhpMyAdmin\Url::getFromRoute("/server/replication");
            yield "\" data-post=\"";
            yield ($context["replica_control_full_link"] ?? null);
            yield "\">
                  ";
            // line 43
            yield ((( !($context["replica_io_running"] ?? null) ||  !($context["replica_sql_running"] ?? null))) ? ("Full start") : ("Full stop"));
            yield "
                </a>
              </li>
              <li>
                <a class=\"ajax\" id=\"reset_replica\" href=\"";
            // line 47
            yield PhpMyAdmin\Url::getFromRoute("/server/replication");
            yield "\" data-post=\"";
            yield ($context["replica_control_reset_link"] ?? null);
            yield "\">
                  ";
yield _gettext("Reset replica");
            // line 49
            yield "                </a>
              </li>
              <li>
                <a href=\"";
            // line 52
            yield PhpMyAdmin\Url::getFromRoute("/server/replication");
            yield "\" data-post=\"";
            yield ($context["replica_control_sql_link"] ?? null);
            yield "\">
                  ";
            // line 53
            if ( !($context["replica_sql_running"] ?? null)) {
                // line 54
                yield "                    ";
yield _gettext("Start SQL Thread only");
                // line 55
                yield "                  ";
            } else {
                // line 56
                yield "                    ";
yield _gettext("Stop SQL Thread only");
                // line 57
                yield "                  ";
            }
            // line 58
            yield "                </a>
              </li>
              <li>
                <a href=\"";
            // line 61
            yield PhpMyAdmin\Url::getFromRoute("/server/replication");
            yield "\" data-post=\"";
            yield ($context["replica_control_io_link"] ?? null);
            yield "\">
                  ";
            // line 62
            if ( !($context["replica_io_running"] ?? null)) {
                // line 63
                yield "                    ";
yield _gettext("Start IO Thread only");
                // line 64
                yield "                  ";
            } else {
                // line 65
                yield "                    ";
yield _gettext("Stop IO Thread only");
                // line 66
                yield "                  ";
            }
            // line 67
            yield "                </a>
              </li>
            </ul>
          </div>
        </li>
        <li>
          <a href=\"#replica_errormanagement_href\" id=\"replica_errormanagement_href\">
            ";
yield _gettext("Error management:");
            // line 75
            yield "          </a>
          <div id=\"replica_errormanagement_gui\" class=\"hide\">
            ";
            // line 77
            yield $this->env->getFilter('error')->getCallable()(_gettext("Skipping errors might lead into unsynchronized primary and replica!"));
            yield "
            <ul>
              <li>
                <a href=\"";
            // line 80
            yield PhpMyAdmin\Url::getFromRoute("/server/replication");
            yield "\" data-post=\"";
            yield ($context["replica_skip_error_link"] ?? null);
            yield "\">
                  ";
yield _gettext("Skip current error");
            // line 82
            yield "                </a>
              </li>
              <li>
                <form method=\"post\" action=\"";
            // line 85
            yield PhpMyAdmin\Url::getFromRoute("/server/replication");
            yield "\">
                  ";
            // line 86
            yield PhpMyAdmin\Url::getHiddenInputs("", "");
            yield "
                  ";
            // line 87
            yield Twig\Extension\CoreExtension::sprintf(_gettext("Skip next %s errors."), "<input type=\"text\" name=\"sr_skip_errors_count\" value=\"1\" class=\"repl_gui_skip_err_cnt\">");
            yield "
                  <input class=\"btn btn-primary\" type=\"submit\" name=\"sr_replica_skip_error\" value=\"";
yield _gettext("Go");
            // line 88
            yield "\">
                  <input type=\"hidden\" name=\"sr_take_action\" value=\"1\">
                </form>
              </li>
            </ul>
          </div>
        </li>
        <li>
          <a href=\"";
            // line 96
            yield PhpMyAdmin\Url::getFromRoute("/server/replication");
            yield "\" data-post=\"";
            yield ($context["reconfigure_primary_link"] ?? null);
            yield "\">
            ";
yield _gettext("Change or reconfigure primary server");
            // line 98
            yield "          </a>
        </li>
      </ul>
    </div>
  ";
        } elseif ( !        // line 102
($context["has_replica_configure"] ?? null)) {
            // line 103
            yield "    ";
            $___internal_parse_0_ = ('' === $tmp = \Twig\Extension\CoreExtension::captureOutput((function () use (&$context, $macros, $blocks) {
                // line 107
                yield "      ";
yield _gettext("This server is not configured as replica in a replication process. Would you like to %sconfigure%s it?");
                // line 108
                yield "    ";
                return; yield '';
            })())) ? '' : new Markup($tmp, $this->env->getCharset());
            // line 103
            yield Twig\Extension\CoreExtension::sprintf($___internal_parse_0_, (((("<a href=\"" . PhpMyAdmin\Url::getFromRoute("/server/replication")) . "\" data-post=\"") . PhpMyAdmin\Url::getCommon(Twig\Extension\CoreExtension::merge(($context["url_params"] ?? null), ["replica_configure" => true, "repl_clear_scr" => true]))) . "\">"), "</a>");
            // line 109
            yield "  ";
        }
        // line 110
        yield "  </div>
</div>
";
        return; yield '';
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName()
    {
        return "server/replication/replica_configuration.twig";
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
        return array (  302 => 110,  299 => 109,  297 => 103,  293 => 108,  290 => 107,  287 => 103,  285 => 102,  279 => 98,  272 => 96,  262 => 88,  257 => 87,  253 => 86,  249 => 85,  244 => 82,  237 => 80,  231 => 77,  227 => 75,  217 => 67,  214 => 66,  211 => 65,  208 => 64,  205 => 63,  203 => 62,  197 => 61,  192 => 58,  189 => 57,  186 => 56,  183 => 55,  180 => 54,  178 => 53,  172 => 52,  167 => 49,  160 => 47,  153 => 43,  147 => 42,  141 => 38,  134 => 35,  131 => 34,  125 => 31,  121 => 30,  115 => 28,  112 => 27,  106 => 25,  104 => 24,  101 => 23,  99 => 22,  96 => 21,  89 => 16,  85 => 15,  76 => 12,  69 => 11,  65 => 10,  62 => 9,  56 => 7,  51 => 6,  48 => 5,  46 => 4,  42 => 2,  38 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "server/replication/replica_configuration.twig", "/var/www/html/phpmyadmin/templates/server/replication/replica_configuration.twig");
    }
}
