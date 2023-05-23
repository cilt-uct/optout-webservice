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

/* footer.html */
class __TwigTemplate_4a025ccd03fb91d6dd06e0908383b9f8 extends Template
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
        $__internal_6f47bbe9983af81f1e7450e9a3e3768f->enter($__internal_6f47bbe9983af81f1e7450e9a3e3768f_prof = new \Twig\Profiler\Profile($this->getTemplateName(), "template", "footer.html"));

        // line 1
        echo "<footer>
    <div class=\"row justify-content-center\">
        <div class=\"col-sm-2\">
            <a title=\"Centre for Innovation in Learning and Teaching\" href=\"http://www.cilt.uct.ac.za/\">Centre for Innovation in Learning and Teaching</a>
            <br/>
            <span>Email: </span><a href=\"mailto:cilt-helpdesk@uct.ac.za?subject=Automated Setup of Lecture Recording ";
        // line 6
        if (array_key_exists("dept", $context)) {
            echo " for ";
            echo twig_escape_filter($this->env, (isset($context["dept"]) || array_key_exists("dept", $context) ? $context["dept"] : (function () { throw new RuntimeError('Variable "dept" does not exist.', 6, $this->source); })()), "html", null, true);
        }
        echo "\" title=\"Cilt Help Desk\">cilt-helpdesk@uct.ac.za</a>
            <br/>
            <span>Phone: 021-650-5500</span>
        </div>
    </div>
</footer>";
        
        $__internal_6f47bbe9983af81f1e7450e9a3e3768f->leave($__internal_6f47bbe9983af81f1e7450e9a3e3768f_prof);

    }

    public function getTemplateName()
    {
        return "footer.html";
    }

    public function isTraitable()
    {
        return false;
    }

    public function getDebugInfo()
    {
        return array (  47 => 6,  40 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("<footer>
    <div class=\"row justify-content-center\">
        <div class=\"col-sm-2\">
            <a title=\"Centre for Innovation in Learning and Teaching\" href=\"http://www.cilt.uct.ac.za/\">Centre for Innovation in Learning and Teaching</a>
            <br/>
            <span>Email: </span><a href=\"mailto:cilt-helpdesk@uct.ac.za?subject=Automated Setup of Lecture Recording {% if dept is defined %} for {{dept}}{% endif %}\" title=\"Cilt Help Desk\">cilt-helpdesk@uct.ac.za</a>
            <br/>
            <span>Phone: 021-650-5500</span>
        </div>
    </div>
</footer>", "footer.html", "/var/www/html/templates/footer.html");
    }
}
