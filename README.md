# RockTabulator

Goals

* Set Data easily
  * via SQL
  * via PHP / RockFinder
  * via JS -> eg runtime tables, aggregations of other tables
* Good documentation
* Events for interaction (init, draw, redraw?)
* Extendable via plugins
  * custom formatters, filters etc. in the module
  * easy for the user to add own plugins
  * plugins should be built in a way that they can be submitted as PR directly to tabulator (eg smart filter could be a good contribution)

Features

* column aggregations
* smart filter
* rowActions
* tooltips
* icons
* buttons
* batcher (https://processwire.com/talk/topic/15524-previewdiscussion-rockdatatables/?do=findComment&comment=167165)
* action buttons (+batcher)
* ProcessModule for Preview/Debugging
* easy API to get data (get rows, cols, selections etc)

Formatters

* colored cells
* colored bars / progress bars / quota bars
* yes/no columns (https://i.imgur.com/P8Bhmfw.png)
* icons before/after aways/on hover
* currency
* date
* numbers (digits after comma)
