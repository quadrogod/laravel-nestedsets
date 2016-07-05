# laravel-nestedsets
Package for manipuate nestedsets in Laravel without Eloquent ORM

# How to use
1. Create: $nestedset = Nestedsets::factory('TableName');
2. Move:
  1 $nestedset->move('current_item_id', 'sibiling_item_id')->before(); // This move item before other element
  2 $nestedset->move('current_item_id', 'parent_item_id' or 0)->asChild(); // This move item as last element to parent
3. Delete: $nestedset->deleteNode('item_id'); // This delete node and all childs

