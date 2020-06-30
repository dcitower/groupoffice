Ext.define('go.modules.community.history.LogEntryGrid',{
	extend: go.grid.GridPanel,

	layout:'fit',
	autoExpandColumn: 'name',

	// input json object output html
	renderJson: function(json, name) {

		var html = [];
		if(!json) {
			return html;
		}

		if(Ext.isDate(json)) {
			// skip for now
			html.push('datum');
		} else if(Ext.isArray(json)) {
			html.push('<b>' + name + '</b> ');
			for(var i = 0 ; i < json.length; i++) {
				html.push.apply(html, this.renderJson(json[i], ' - '));
			}
		} else if (json === null) {
			html.push('<b>' + name + '</b> null');
		} else if(typeof json === 'object') {
			//html.push('<b>' + key + '</b> ');
			for(var key in json) {
				html.push.apply(html, this.renderJson(json[key], key));
				//html.push(' - <b>' + key + '</b> ' + json[key]);
			}
		} else { // string number bool
			html.push('<b>' + name + '</b> ' + json);
		}

		return html;
	},

	renderJsonValue: function(data) {
		var html = [];
		if(data === null) {
			html.push('<i>null</i>');
		} else if(Ext.isArray(data)) {
			for(var i = 0 ; i < data.length; i++) {
				if(i !== 0) {
					html.push(''); // extra enter
				}
				html.push.apply(html, this.renderJsonValue(data[i]));
			}
		} else if(typeof data === 'object') {
			//html.push.apply(html, this.renderJson(data));
			for(var key in data) {
				html.push('<b>' + key + '</b> ' + data[key]);
			}
		} else {
			html.push(data);
		}
		return html;
	},

	renderOldNew: function(json) {
		if(!json) {
			return [];
		}
		html = ['<table style="width:100%;border-spacing: 3px"><tr><th>'+t('Name')+'</th><th>'+t('Old')+'</th><th>'+t('New')+'</th></tr>'];
		for(var key in json) {
			html.push('<tr><td>'+key+':</td><td>'+this.renderJsonValue(json[key][1]).join('<br>')+
				'</td><td>'+this.renderJsonValue(json[key][0]).join('<br>')+'</td></tr>');
		}
		html.push('</tr></table>');
		return html;
	},

	initComponent: function() {
		Ext.applyIf(this,{
			store: new go.data.Store({
				fields: [{name:'createdAt',type:'date'},'id', 'entity', 'action','changes','createdBy', 'description',{name: 'creator', type: "relation"}],
				baseParams: {sort: [{property: "createdAt", isAscending:false}]},
				entityStore: "LogEntry"
			}),
			viewConfig: {emptyText: '<i>description</i><p>' + t("Item was never modified",'community','history') + '</p>'},
			columns:[{
				header: t('ID'),
				width: dp(80),
				dataIndex: 'id',
				hidden:true,
				align: "right"
			},{
				header: t('Name'),
				dataIndex: 'description',
				id: 'name'
			},{
				header: t('Entity'),
				dataIndex: 'entity',
				id: 'entity'
			},{
				header: t('Changes'),
				xtype: 'actioncolumn',
				width: dp(80),
				items: [{
					iconCls: 'ic-note',
					handler: function(grid, rowIndex, colIndex) {
						var rec = grid.store.getAt(rowIndex),
							json = JSON.parse(rec.data.changes);

						if(!json) {
							return;
						}

						var target = grid.view.getCell(rowIndex, colIndex),
							html = '';

						switch(rec.data.action) {
							case 'update': html = this.renderOldNew(json).join('');
								break;
							case 'create':
							case 'delete': html = this.renderJson(json).join('<br>');
								break;
							case 'login': html = rec.data.createdAt;
								break;
						}

						var tt = new Ext.menu.Menu({
							//target: target,
							//title: rec.data.description,
							width:500,
							html: '<div style="padding:7px"><h5>'+rec.data.description+'</h5>'+html+'</div>' ,
							autoHide: false
							//closable: true
						});
						tt.show(target);
					},
					scope:this
				}]
			},{
				header: t('Action'),
				dataIndex: 'action',
				renderer: function(v, meta, r) {
					return t(v.charAt(0).toUpperCase() + v.slice(1));
					//return go.Modules.registered.community.history.actionTypes[v] || 'Unknown';
				}
			},{
				xtype: "datecolumn",
				header: t('Date'),
				dataIndex: 'createdAt',

			},{
				header: t('User'),
				dataIndex: 'creator',
				width:300,
				renderer: function (v) {
					return v ? v.displayName : "-";
				}
			}]
		});

		this.callParent();

		this.on('afterrender',function(){this.store.load();},this);
	}
});