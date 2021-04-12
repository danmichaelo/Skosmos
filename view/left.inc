<div class="welcome-box">

  {% if request.lang == 'nb' %}
  <h1>Velkommen til vokabulartjenesten</h1>
  <p>
    Her publiserer vi åpne vokabularer fra Biblioteksentralen og andre.
    Dataene oppdateres normalt daglig.
    Tjenesten har åpne programmeringsgrensesnitt.
  </p>

  {% elseif request.lang == 'nn' %}
  <h1>Velkommen til vokabulartenesten</h1>
  <p>TODO</p>

  {% elseif request.lang == 'en' %}
  <h1>Welcome to the vocabulary service</h1>
  <p>TODO</p>

  {% endif %}
</div>
