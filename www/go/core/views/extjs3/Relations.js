go.Relations = {

	TYPE_MAP : 'map',
	TYPE_SCALAR : 'scalar',
	TYPE_ARRAY : 'array',
	TYPE_HAS_ONE : 'hasone',

  entityStore: null,
  get : function (entityStore, entity, relations) {

    this.entityStore = entityStore;
    this.watchRelations = {};

    var me = this;

    var promises = [];
    relations.forEach(function(relName) {
      promises.push(me.getRelation(relName, entity));
    });  

		return Promise.all(promises).then(function() {
			return {entity: entity, watch: me.watchRelations};
		});		
  },

  /**
	 * Create a promise that resolves the relational record data.
	 * 
	 * @param {string|object} relName Relation name or object with {name: "users", limit: 5}. This will only resolve the first 5 entities and put the total in record.json._meta.users.total
	 * @param {object} entity
	 * 
	 * @return {Promise}
	 */
	getRelation : function(relName, entity) {

		var c = {};
		if(Ext.isObject(relName)) {
			c = relName;
			relName = c.name;
		}

		var relation = this.entityStore.entity.findRelation(relName);

		if(!relation) {
			return Promise.reject("Relation " + relName + " not found for " + this.entityStore.entity.name);
		}

		return this.resolveKey(relName, entity);

		/*if(!key) {
            me.applyRelationEntity(relation.path + relName, entity, null);
			return Promise.resolve(null);
		}		

		if(Ext.isArray(key)) {

			if(c.limit) {
				entity._meta = entity._meta || {};
				entity._meta[relName] = {total: key.length};

				key = key.slice(0, c.limit);				
			}

			key.forEach(function(k) {
				me.watchRelation(relation.store, k);
			});

			return go.Db.store(relation.store).get(key).then(function(result) {
				me.applyRelationEntity(relName, entity, result.entities);
			});
		}

		this.watchRelation(relation.store, key);

		return go.Db.store(relation.store).single(key).then(function(relatedEntity) {
			me.applyRelationEntity(relName, entity, relatedEntity);
		});*/
	},

	/**
	 * Keeps record of relational entity stores and their id's. go.data.Stores uses this collection to listen for changes
	 * 
	 * @param {string} entity 
	 * @param {int} key 
	 */
	watchRelation : function(entity, key) {
		if(!this.watchRelations[entity]) {
			this.watchRelations[entity] = [];
		}

		if(this.watchRelations[entity].indexOf(key) === -1) {
			this.watchRelations[entity].push(key);
		}
	},

	/**
	 * Applies the entity data to the record.
	 * It also supports a path like "customFields.user"
	 * 
	 * This will become
	 * {
	 * 	"customFields" => {
	 * 		"user" => data
	 * 	}
	 * }
	 * @param {*} key 
	 * @param {*} record 
	 * @param {*} entities 
	 * /
	applyRelationEntity : function(relName, data, entities) {
		var parts = relName.split("."),last = parts.pop();
		var relation = this.entityStore.entity.relations;

		parts.forEach(function(p) {
			relation = relation[p];
			if(relation.fk) {
				p = relation.fk;
			}

			data = data[p];

			if(relation.type == go.Relations.TYPE_MAP) {
				var arr = [];
				for(var id in data){
					this.
				}
				data = arr;
			}
			

		});

		if(Ext.isArray(current)) {
			current.forEach(function(item, index){
				item[last] = Ext.isArray(entities) ? entities[index] : entities;
			});
		}else{
			current[last] = entities;
		}
	},*/

	/**
	 * Resolves a key path eg. "customFields.user"
	 * 
	 * @param {string} key
	 * @param {*} data 
	 */
	resolveKey : function(key, data) {


		var promises = [];

		function fetchData(relation, relName, item) {
			var key = item[relation.fk];
			if(!key) {
				item[relName] = null;
				return;
			}
			promises.push(go.Db.store(relation.store).single(key).then(function(relatedEntity) {
				item[relName] = relatedEntity;
			}).catch(function() {
				item[relName] = null;
			}));
		}

		if(!data) {
			return Promise.resolve();
		}
		var parts = key.split("."), p, arr;
		var relation = this.entityStore.entity.relations;
		for(var i = 0, l = parts.length; i < l; i++) {
			p = relName = parts[i];

			relation = relation[p];
			if(!relation) {
				throw "relation " + p + " does not exist";
			}

			if(relation.fk) {
				p = relation.fk;
			}

			if(!relation.type) {
				if(Ext.isObject(data[p])) {
					relation.type = go.Relations.TYPE_HAS_ONE;
				} else if(Ext.isArray(data[p])) {
					if(data[p].length === 0 || !Ext.isObject(data[p][0])) {
						relation.type = go.Relations.TYPE_SCALAR;
					} else {
						relation.type = go.Relations.TYPE_ARRAY;
					}
				}
			}

			switch(relation.type) {

				case go.Relations.TYPE_MAP:
					data = data[p];
					arr = [];
					for (var id in data) {
						if (relation.fk) {
							fetchData(relation, relName, data);
						} else {
							arr.push(data[id]);
						}
					}
					data = arr;
					break;

				case go.Relations.TYPE_SCALAR:
					var scalar = data[p];
					data[relName] = [];
					scalar.forEach(function (key) {
						promises.push(go.Db.store(relation.store).single(key).then(function(relatedEntity) {
							data[relName].push(relatedEntity);
						}).catch(function() {
							//??? Not found id?
						}));
					});
					break;

				case go.Relations.TYPE_ARRAY:

					data = data[p];
					arr = [];
					data.forEach(function (item) {
						if (relation.fk) {
							fetchData(relation, relName, item);
						} else {
							arr.push(item);
						}
					});
					if (!relation.fk) {
						data = arr;
					}
					break;

				default:
					if(Ext.isArray(data)) {
						arr = [];
						data.forEach(function (item) {
							if (relation.fk) {
								fetchData(relation, relName, item);
							} else {
								arr.push(item);
							}
						});
						if (!relation.fk) {
							data = arr;
						}
					} else {
						if (relation.fk) {
							fetchData(relation, relName, data);
						} else {
							data = data[p];
						}
					}
				break;
			}

			if(!data) {
				return Promise.resolve();
			}
		}
		
		return Promise.all(promises);
	}
};