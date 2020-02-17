
(function() {
    function checkArray(part) {
        var matches = part.match(/\[([0-9]*)\]/);
        if(!matches) {
            return -1;
        }

        return matches[1];
    }

    function traverse(obj, part, value) {
        var arrayIndex = checkArray(part);
        if(arrayIndex != -1) {
            part = part.replace(/\[[0-9]*\]/, "");

            if (!obj[part] || !Ext.isArray(obj[part])) {
                if(Ext.isDefined(value)) {
                    obj[part] = [];
                } else
                {
                    return null;
                }
            }

            if(arrayIndex === "") {
                arrayIndex = obj[part].length;
            }
            if(!obj[part][arrayIndex]) {
                if(Ext.isDefined(value)) {
                    obj[part][arrayIndex] = value;
                } else
                {
                    return null;
                }
            }
            obj = obj[part][arrayIndex];
        } else {

            if (!obj[part]) {
                if(!Ext.isDefined(value)) {
                    return null;
                }
                obj[part] = value;
            }
            obj = obj[part];
        }

        return obj;
    }


    go.util.Object = {
        /**
         * Set's value on the object to the give path.
         *
         * eg.
         *
         * var o = {};
         *
         * o.applyPath("foo.bar", "test");
         * o.applyPath("foo.anArray[]", "test");
         *
         * will result in:
         *
         * {"foo": {"bar": "test", "anArray": ["test"]}
         *
         * @param path eg foo.bar
         * @param value
         * @return Deepest child
         */
        applyPath : function(obj, path, value) {

            var parts = path.split(".");
            var last = parts.pop();
            var ret;

            parts.forEach(function(part) {
                ret = obj = traverse(obj, part, {});
            });

            traverse(obj, last, value);

            return ret;
        },

        fetchPath : function(obj, path) {
            var parts = path.split(".");
            var last = parts.pop();

            for(var i = 0, l = parts.length; i < l; i++) {
                if(!traverse(obj, parts[i])) {
                    return null;
                }
            }

            return traverse(obj, last, value);
        },

        values : function(obj) {
            var vals = [];
            for (var key in obj) {
                vals.push(obj[key]);
            }
            return vals;
        },

        /**
         * Convert a mapped relation to a flat array. The key for each object element is the identifier (e.g. id)
         * for within the value. Normally this would be ID
         * @param obj
         * @param i optional override for identifier key
         * @returns {*}
         */
        convertMapToArray : function(obj, i) {
            i = i || "id";
            var arRet = Object.values(obj),arKeys = Object.keys(obj);
            for(var idx=0;idx<arRet.length;idx++) {
                var k = arKeys[idx],v = arRet[idx];
                if(!v[i]) {
                    v[i] = Number(k);
                }
            }
            return arRet;
        }
    };

})();