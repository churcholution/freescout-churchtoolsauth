(function () {
    var module_routes = [
    {
        "uri": "app-settings\/churchtoolsauth\/ajax",
        "name": "churchtoolsauth.ajax"
    },
    {
        "uri": "app-settings\/churchtoolsauth\/ajax-search",
        "name": "churchtoolsauth.ajax_search"
    }
];

    if (typeof(laroute) != "undefined") {
        laroute.add_routes(module_routes);
    } else {
        contole.log('laroute not initialized, can not add module routes:');
        contole.log(module_routes);
    }
})();