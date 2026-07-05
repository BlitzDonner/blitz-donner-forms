![[CleanShot 2026-07-05 at 07.52.58@2x.jpg]]

1. Absender-E-Mail: Bei der Absender E-Mail. Wenn man das E-Mail-Feld wählt als Absender, muss eine Hinweise erscheinen, dass das problematisch sein kann mit einigen Servern, weil das als Spam gewettet wird. Gibt es da eine Möglichkeit, diese Problematik zu umgehen? 
   ✅ erledigt (v0.4.0): Die Problematik ist umgangen statt nur beschriftet. Das gewählte E-Mail-Feld wird als **Reply-To** gesetzt («Antworten» geht an die Absenderin), der technische From bleibt immer eine Adresse der Website (eigene Adresse oder Admin) – so scheitert nichts an SPF/DKIM/DMARC. Der Hinweis dazu steht neu im Hilfetext des Absender-Dropdowns.
2. Radio Buttons Bei den Radiobuttons gehört die ausrichtung (untereinander / nebeneinander) m.E. in die Werkzeugleiste, nicht in die Sidebar
   ✅ erledigt (v0.4.0): Zwei Knöpfe in der Block-Werkzeugleiste (untereinander/nebeneinander); das Dropdown in der Seitenleiste ist weg.
3. Label können ja entfernt werden , wenn nichts drin steht soll die aria und tecnischer feldname aus dem Platzhalter benannt werden. 
   ✅ erledigt (v0.4.0): Ohne Label bekommt das Eingabefeld ein aria-label aus dem Platzhalter. Der technische Feldname wird bereits aus dem Platzhalter abgeleitet, wenn beim Vergeben kein Label da ist (Standard-Labels wie «Textfeld» gelten als neu ableitbar).
4. Platzhaltertext in den Feldern soll in der gleichen Farbe wie der Hilfetext sein. 
   ✅ erledigt (v0.4.0): Platzhalterfarbe folgt standardmässig der Label-/Hilfetextfarbe; eine eigene Platzhalterfarbe im Block übersteuert weiterhin.
5. Bei Pflichtfeldern ohne Label muss die Pflichtfeld-Markierung hinter dem Eingabefeld platziert werden. 
   ✅ erledigt (v0.4.0): Stern schwebt am rechten Rand des Eingabefelds – im Frontend und im Editor-Canvas identisch.
6. Bei Datenfeldern muss mit einem Dropdown gewählt werden können, welche dateitypen hochgeladen werden können. 
   ✅ erledigt (v0.4.0): Statt des freien accept-Felds gibt es Schalter für Typgruppen (Bilder, PDF, Word, Tabellen, Präsentationen, Text, ZIP – exakt die Server-Whitelist). Nichts gewählt = alle sicheren Standardtypen.
7. bei den Dateien erscheint: Datei wird geschützt abgelegt, ohne öffentlichen Link (max. 8 MB). Das stimmt ja nicht in der Basisversion – bitte korrigieren, aber so dass es wieder drin ist, sobald man die Security-Erweiterung lizenziert hat. Mach dafür einen Warnhinweis (sanft) im backend, dass man sich damit auch gefährliche Dateien einhandeln kann. 
   ✅ erledigt (v0.4.0): Basis zeigt neutral «Maximale Dateigrösse: X MB.»; mit aktivem Security-Add-on (Filter bdfrms_sensitive_ui_active) erscheint «Datei wird verschlüsselt und geschützt gespeichert». Im Editor des Datei-Blocks steht neu eine sanfte Warnung, dass Uploads schädliche Dateien enthalten können und Virenscan/verschlüsselte Ablage das Security-Add-on liefert.
   Alle Punkte in WP Playground verifiziert (Header, aria, Stern, Hinweistexte); PHPCS und CI grün, deployt auf blitz-donner-forms.local.