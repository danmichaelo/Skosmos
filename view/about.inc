{% if request.lang == 'nb' %}

	<h4>Biblioteksentralens vokabulartjeneste</h4>

	<p>
        Vokabulartjenesten inneholder åpne vokabularer fra Biblioteksentralen og andre.
        Tjenesten har erstattet den gamle betalingstjenesten for Bibbi autoriteter.
        Savner du noe her, er det bare å <a href="http://localhost:8002/nb/feedback">ta kontakt</a>.
	</p>

	<h4>Om Skosmos</h4>
	<p>
		Skosmos er en web-basert åpen programvare for å navigere i SKOS-baserte vokabularer.
	</p>
	<a href="http://github.com/NatLibFi/Skosmos">Skosmos på GitHub</a>

{% elseif request.lang == 'nn' %}

	<h4>Biblioteksentralen si vokabularteneste</h4>
	<p>
		Vi nyttar Skosmos for å gjere tilgjengeleg ei eksperimentell, åpen utgåve av Bibbi autoritetar som RDF/SKOS.
		Konverteringa er i startfasen, datamodellen vil utviklast over tid.
	</p>

	<h4>Om Skosmos</h4>
	<p>
		Skosmos er ei web-basert åpen programvare for å navigere i SKOS-baserte vokabular.
	</p>
	<a href="http://github.com/NatLibFi/Skosmos">Skosmos på GitHub</a>

{% elseif request.lang == 'en' %}

	<h4>Biblioteksentralen vocabulary service</h4>

	<p>
		We're using Skosmos to publish an experimental, open version of Bibbi authorities as RDF/SKOS.
		The data model will be developed over time.
	</p>

	<h4>About Skosmos</h4>
	<p>
		Skosmos is a web based open source ontology browser.
	</p>
	<a href="http://github.com/NatLibFi/Skosmos">Skosmos GitHub repository</a>

{% endif %}
