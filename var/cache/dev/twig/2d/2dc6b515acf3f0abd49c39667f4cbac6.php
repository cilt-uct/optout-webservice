<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;

/* admin_login.html.twig */
class __TwigTemplate_819f85969f56bc6ca25d954a68f7e4e0 extends Template
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
        $__internal_6f47bbe9983af81f1e7450e9a3e3768f = $this->extensions["Symfony\\Bridge\\Twig\\Extension\\ProfilerExtension"];
        $__internal_6f47bbe9983af81f1e7450e9a3e3768f->enter($__internal_6f47bbe9983af81f1e7450e9a3e3768f_prof = new \Twig\Profiler\Profile($this->getTemplateName(), "template", "admin_login.html.twig"));

        // line 1
        $this->loadTemplate("html_start.html", "admin_login.html.twig", 1)->display($context);
        // line 2
        echo "    <div class=\"content\">
        ";
        // line 3
        $this->loadTemplate("header.html", "admin_login.html.twig", 3)->display($context);
        // line 4
        echo "        <div class=\"container post\">
            <div class=\"row post-body justify-content-md-center\">
                <div class=\"col-md-5 post-title\">
                    <form id=\"loginForm\" method=\"post\" action=\"/optout/admin\">
                        <input type=\"hidden\" id=\"type\" name=\"type\" value=\"login\"/>
                        <div class=\"modal-body\">
                            <div class=\"form-group row\">
                                <div class=\"col-sm-12\">
                                ";
        // line 12
        if (((isset($context["err"]) || array_key_exists("err", $context) ? $context["err"] : (function () { throw new RuntimeError('Variable "err" does not exist.', 12, $this->source); })()) != "none")) {
            // line 13
            echo "                                    <div class=\"alert alert-danger\" role=\"alert\">";
            echo twig_escape_filter($this->env, (isset($context["err"]) || array_key_exists("err", $context) ? $context["err"] : (function () { throw new RuntimeError('Variable "err" does not exist.', 13, $this->source); })()), "html", null, true);
            echo "</div>
                                ";
        }
        // line 15
        echo "                                </div>
                            </div>
                            <div class=\"form-group row\">
                                <div class=\"col-sm-12\">
                                    <input type=\"text\" class=\"form-control\" name=\"eid\" id=\"eid\" value=\"\" placeholder=\"User ID\" style=\"width:100%\">
                                </div>
                            </div>
                            <div class=\"form-group row\">
                                <div class=\"col-sm-12\">
                                    <input type=\"password\" class=\"form-control\" name=\"pw\" id=\"pw\" placeholder=\"Password\" style=\"width:100%\">
                                </div>
                            </div>
                        </div>
                        <div class=\"modal-footer\">
                            <button type=\"submit\" class=\"btn btn-primary\">Login</button>
                            <button type=\"button\" class=\"btn btn-secondary\" data-dismiss=\"modal\">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
";
        // line 37
        $this->loadTemplate("footer.html", "admin_login.html.twig", 37)->display($context);
        // line 38
        $this->loadTemplate("html_end.html", "admin_login.html.twig", 38)->display($context);
        
        $__internal_6f47bbe9983af81f1e7450e9a3e3768f->leave($__internal_6f47bbe9983af81f1e7450e9a3e3768f_prof);

    }

    public function getTemplateName()
    {
        return "admin_login.html.twig";
    }

    public function isTraitable()
    {
        return false;
    }

    public function getDebugInfo()
    {
        return array (  91 => 38,  89 => 37,  65 => 15,  59 => 13,  57 => 12,  47 => 4,  45 => 3,  42 => 2,  40 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("{% include 'html_start.html' %}
    <div class=\"content\">
        {% include 'header.html' %}
        <div class=\"container post\">
            <div class=\"row post-body justify-content-md-center\">
                <div class=\"col-md-5 post-title\">
                    <form id=\"loginForm\" method=\"post\" action=\"/optout/admin\">
                        <input type=\"hidden\" id=\"type\" name=\"type\" value=\"login\"/>
                        <div class=\"modal-body\">
                            <div class=\"form-group row\">
                                <div class=\"col-sm-12\">
                                {% if err != 'none' %}
                                    <div class=\"alert alert-danger\" role=\"alert\">{{err}}</div>
                                {% endif %}
                                </div>
                            </div>
                            <div class=\"form-group row\">
                                <div class=\"col-sm-12\">
                                    <input type=\"text\" class=\"form-control\" name=\"eid\" id=\"eid\" value=\"\" placeholder=\"User ID\" style=\"width:100%\">
                                </div>
                            </div>
                            <div class=\"form-group row\">
                                <div class=\"col-sm-12\">
                                    <input type=\"password\" class=\"form-control\" name=\"pw\" id=\"pw\" placeholder=\"Password\" style=\"width:100%\">
                                </div>
                            </div>
                        </div>
                        <div class=\"modal-footer\">
                            <button type=\"submit\" class=\"btn btn-primary\">Login</button>
                            <button type=\"button\" class=\"btn btn-secondary\" data-dismiss=\"modal\">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
{% include 'footer.html' %}
{% include 'html_end.html' %}", "admin_login.html.twig", "/var/www/html/templates/admin_login.html.twig");
    }
}
