/* global go, Ext */

(function () {
  
  var entities = {};
  var stores = {};
  
  go.Entities = {

    /**
     * Register an entity
     * 
     * this will create a global entity and store:
     * 
     * go.Stores.get("name")]
     * go.Entities.get(name)
     * 
     * @param {string} name
     * @param {object} jmapMethods
     * @returns {undefined}
     */
    register: function (package, module, config) {
			
			if(!Ext.isObject(config)) {
				config = {
					name: config,
					linkable: false,
					searchable: false
				}
			}
			
			if(!config.name) {
				throw "Invalid entity registered. 'name' property is required."
			}
			
      if(entities[config.name]) {
        throw "Entity name is already registered by module " +entities[name]['package'] + "/" + entities[name]['name'];
      }
      
      entities[config.name.toLowerCase()] = Ext.applyIf(config, {        
        module: module,
        package: package,
				title: t(config.name),
				getRouterPath : function (id) {
					return this.name.toLowerCase() + "/" + id;
				},
        goto: function (id) {
          go.Router.goto(this.getRouterPath(id));
        }
			
      });     
			
			//these datatypes will be prefetched by go.data.JmapProxy.fetchEntities()
			// Key can also be a function that is called with the record data.
			go.data.types[config.name] = {
				convert: function (v, data) {

					var key = this.type.getKey.call(this, data);
					
					if(!key) {
						return null;
					}
					
					var entities = go.Stores.get(config.name).get([key]);
					return entities ? entities[0] : null;	
				},
				
				getKey : function(data) {
					if(typeof(this.key) == "function") {
						return this.key.call(this, data);
					} else
					{
						if(!this.key) {
							throw "Key is undefined";
						}	

						return data[this.key];
					}
				},
				sortType: Ext.data.SortTypes.none,
				type: "entity",
				entity: config.name
			}
    },

    get: function (name) {      
      return entities[name.toLowerCase()];      
    },
    
    getAll: function() {
      return entities;
    }
  };
  
  
  go.Stores = {
    get: function (name) {
      
      name = name.toLowerCase();
			
			var entity = go.Entities.get(name);
			if(!entity) {
				return false;
			}
     
      if(!stores[name]) {
        stores[name] = new go.data.EntityStore({
          entity: entity
        });
      }
      
      return stores[name];
    }
  }

})();
